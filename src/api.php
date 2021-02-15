<?php
session_cache_limiter('nocache');
require 'config.php';
require 'config-private.php';

header('Content-Type: application/json; charset=utf-8');

$date_today = date_create('now');
$date_today = date_format($date_today, 'md');
$timestamp_now = date_create('now');
$timestamp_now = date_format($timestamp_now, 'YmdHi');

/**Retrieve cached data
 * Session array:
 * 0: Date Gigya JWT Token request (md)
 * 1: Gigya JWT Token
 * 2: Renault account id
 * 3: MD5 hash of the last data retrieval
 * 4: Timestamp of the last data retrieval (YmdHi)
 * 5: Mail sent (Y/N)
 * 6: Car is charging (Y/N)
 * 7: Mileage
 * 8: Date status update
 * 9: Time status update
 * 10: Charging status
 * 11: Cable status
 * 12: Battery level
 * 13: Battery temperature (Ph1) / battery capacity (Ph2)
 * 14: Range in km
 * 15: Charging time
 * 16: Charging effect
 * 17: Outside temperature (Ph1) / GPS-Latitude (Ph2)
 * 18: GPS-Longitude (Ph2)
 * 19: GPS date (Ph2, d.m.Y)
 * 20: GPS time (Ph2, H:i)
 * 21: Setting battery level for mail function
 * 22: Outside temperature (Ph2, openweathermap API)
 * 23: Weather condition (Ph2, openweathermap API)
 * 24: Chargemode
 */

$session = file_get_contents('api-session');
if ($session !== FALSE) $session = explode('|', $session);
else $session = array('0000', '', '', '', '202101010000', 'N', 'N', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '80','','','');

//Retrieve new Gigya token if the date has changed since last request
if (empty($session[1]) || $session[0] !== $date_today) {
    //Login Gigya
    $postData = array(
      'ApiKey' => $gigya_api,
      'loginId' => $username,
      'password' => $password,
      'include' => 'data',
      'sessionExpiration' => 60
    );
    $ch = curl_init('https://accounts.eu1.gigya.com/accounts.login');
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    $response = curl_exec($ch);
    if ($response === FALSE) die(curl_error($ch));  
    $responseData = json_decode($response, TRUE);
    $personId = $responseData['data']['personId'];
    $oauth_token = $responseData['sessionInfo']['cookieValue'];
  
    //Request Gigya JWT token
    $postData = array(
      'login_token' => $oauth_token,
      'ApiKey' => $gigya_api,
      'fields' => 'data.personId,data.gigyaDataCenter',
      'expiration' => 87000
    );
    $ch = curl_init('https://accounts.eu1.gigya.com/accounts.getJWT');
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    $response = curl_exec($ch);
    if ($response === FALSE) die(curl_error($ch));
    $responseData = json_decode($response, TRUE);
    $session[1] = $responseData['id_token'];
    
    $session[0] = $date_today;
  }

//Request Renault account id if not cached
if (empty($session[2])) {
    //Request Kamereon account id
    $postData = array(
      'apikey: '.$kamereon_api,
      'x-gigya-id_token: '.$session[1],
    );
    $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/persons/'.$personId.'?country='.$country);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
    $response = curl_exec($ch);
    if ($response === FALSE) die(curl_error($ch));
    $responseData = json_decode($response, TRUE);
    $session[2] = $responseData['accounts'][0]['accountId'];
  }

//Request battery and charging status from Renault
$postData = array(
    'apikey: '.$kamereon_api,
    'x-gigya-id_token: '.$session[1]
  );
  $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/'.$session[2].'/kamereon/kca/car-adapter/v2/cars/'.$vin.'/battery-status?country='.$country);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));
  $md5 = md5($response);
  $responseData = json_decode($response, TRUE);
  $s = date_create_from_format(DATE_ISO8601, $responseData['data']['attributes']['timestamp'], timezone_open('UTC'));
  if (empty($s)) $update_sucess = FALSE;
  else {
    $update_sucess = TRUE;
    $weather_api_dt = date_format($s, 'U');
    $s = date_timezone_set($s, timezone_open('Europe/Berlin'));
    $session[8] = date_format($s, 'd.m.Y');
    $session[9] = date_format($s, 'H:i');
    $session[10] = $responseData['data']['attributes']['chargingStatus'];
    $session[11] = $responseData['data']['attributes']['plugStatus'];
    $session[12] = $responseData['data']['attributes']['batteryLevel'];
    if (($zoeph == 1)) $session[13] = $responseData['data']['attributes']['batteryTemperature'];
    else $session[13] = $responseData['data']['attributes']['batteryAvailableEnergy'];
    $session[14] = $responseData['data']['attributes']['batteryAutonomy'];
    $session[15] = $responseData['data']['attributes']['chargingRemainingTime'];
    $s = $responseData['data']['attributes']['chargingInstantaneousPower'];
    if ($zoeph == 1) $session[16] = $s/1000;
    else $session[16] = $s;
  }

//Cache data
if (($md5 != $session[3] && $update_sucess === TRUE) || $cmd_cron === TRUE || is_numeric($_POST['bl'])) {
    $session[3] = $md5;
    $session[4] = $timestamp_now;
    $session = implode('|', $session);
    file_put_contents('api-session', $session);
  }

//Output  

echo json_encode($session);
?>