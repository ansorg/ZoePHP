<?php
session_cache_limiter('nocache');
require 'config.php';
require 'config-private.php';

header('Content-Type: application/json; charset=utf-8');

// yyyy-MM-dd'T'HH:mm:ss.SSSZ
$date_today = date_create('now');
$iso_now = date_format($date_today, DATE_ATOM);
$date_today = date_format($date_today, 'Y-m-d');
$timestamp_now = date_create('now');
$timestamp_now = date_format($timestamp_now, 'H:i:s');

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
else $session = array('0000', '', '', '', '202101010000', 'N', 'N', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '80', '', '', '');

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
    'apikey: ' . $kamereon_api,
    'x-gigya-id_token: ' . $session[1],
  );
  $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/persons/' . $personId . '?country=' . $country);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));
  $responseData = json_decode($response, TRUE);
  $session[2] = $responseData['accounts'][0]['accountId'];
}

//Request battery and charging status from Renault
$postData = array(
  'apikey: ' . $kamereon_api,
  'x-gigya-id_token: ' . $session[1]
);
$ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/' . $session[2] . '/kamereon/kca/car-adapter/v2/cars/' . $vin . '/battery-status?country=' . $country);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
$response = curl_exec($ch);
if ($response === FALSE) die(curl_error($ch));
$md5 = md5($response);
$responseData = json_decode($response, TRUE);
$s = date_create_from_format(DATE_ISO8601, $responseData['data']['attributes']['timestamp'], timezone_open('UTC'));
if (empty($s)) {
  $update_sucess = FALSE;
} else {
  $update_sucess = TRUE;
  $weather_api_dt = date_format($s, 'U');
  $s = date_timezone_set($s, timezone_open('Europe/Berlin'));
  $session[8] = date_format($s, 'Y-m-d');
  $session[9] = date_format($s, 'H:i:s');
  $session[10] = $responseData['data']['attributes']['chargingStatus'];
  $session[11] = $responseData['data']['attributes']['plugStatus'];
  $session[12] = $responseData['data']['attributes']['batteryLevel'];
  if (($zoeph == 1)) $session[13] = $responseData['data']['attributes']['batteryTemperature'];
  else $session[13] = $responseData['data']['attributes']['batteryAvailableEnergy'];
  $session[14] = $responseData['data']['attributes']['batteryAutonomy'];
  $session[15] = $responseData['data']['attributes']['chargingRemainingTime'];
  $s = $responseData['data']['attributes']['chargingInstantaneousPower'];
  if ($zoeph == 1) $session[16] = $s / 1000;
  else $session[16] = $s;
}
//Request more data from Renault if changed data since last request are expected
if ($md5 != $session[3] && $update_sucess === TRUE) {
  //Request mileage
  $postData = array(
    'apikey: ' . $kamereon_api,
    'x-gigya-id_token: ' . $session[1]
  );
  $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/' . $session[2] . '/kamereon/kca/car-adapter/v1/cars/' . $vin . '/cockpit?country=' . $country);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));
  $responseData = json_decode($response, TRUE);
  $s = $responseData['data']['attributes']['totalMileage'];
  if (empty($s)) $update_sucess = FALSE;
  else $session[7] = $s;

  //Request chargemode
  $postData = array(
    'apikey: ' . $kamereon_api,
    'x-gigya-id_token: ' . $session[1]
  );
  $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/' . $session[2] . '/kamereon/kca/car-adapter/v1/cars/' . $vin . '/charge-mode?country=' . $country);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));
  $responseData = json_decode($response, TRUE);
  $s = $responseData['data']['attributes']['chargeMode'];
  if (empty($s)) $session[24] = 'n/a';
  else $session[24] = $s;

  //Request outside temperature (only Ph1)
  if ($zoeph == 1) {
    $postData = array(
      'apikey: ' . $kamereon_api,
      'x-gigya-id_token: ' . $session[1]
    );
    $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/' . $session[2] . '/kamereon/kca/car-adapter/v1/cars/' . $vin . '/hvac-status?country=' . $country);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
    $response = curl_exec($ch);
    if ($response === FALSE) die(curl_error($ch));
    $responseData = json_decode($response, TRUE);
    $s = $responseData['data']['attributes']['externalTemperature'];
    if (empty($s) && $s != '0.0') $update_sucess = FALSE;
    else $session[17] = $s;
  }

  //Request GPS position (only Ph2)
  if ($zoeph == 2) {
    $postData = array(
      'apikey: ' . $kamereon_api,
      'x-gigya-id_token: ' . $session[1]
    );
    $ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/' . $session[2] . '/kamereon/kca/car-adapter/v1/cars/' . $vin . '/location?country=' . $country);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);
    $response = curl_exec($ch);
    if ($response === FALSE) die(curl_error($ch));
    $responseData = json_decode($response, TRUE);
    $s = date_create_from_format(DATE_ISO8601, $responseData['data']['attributes']['lastUpdateTime'], timezone_open('UTC'));
    if (empty($s)) $update_sucess = FALSE;
    else {
      $s = date_timezone_set($s, timezone_open('Europe/Berlin'));
      $session[17] = $responseData['data']['attributes']['gpsLatitude'];
      $session[18] = $responseData['data']['attributes']['gpsLongitude'];
      $session[19] = date_format($s, 'Y-m-d');
      $session[20] = date_format($s, 'H:i:s');
    }
  }

  //Request weather data from openweathermap (only Ph2)
  if ($zoeph == 2 && $weather_api_key != '') {
    $ch = curl_init('https://api.openweathermap.org/data/2.5/onecall/timemachine?lat=' . $session[17] . '&lon=' . $session[18] . '&dt=' . $weather_api_dt . '&units=metric&lang=' . $weather_api_lng . '&appid=' . $weather_api_key);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $response = curl_exec($ch);
    if ($response === FALSE) die(curl_error($ch));
    $responseData = json_decode($response, TRUE);
    $session[22] = $responseData['current']['temp'];
    $session[23] = $responseData['current']['weather']['0']['description'];
  }

  //Sent mail if configured
  if ($mail_bl === 'Y') {
    if ($session[12] >= $session[21] && $session[10] == 1 && $session[5] != 'Y') {
      if ($session[15] != '') $s = $session[15];
      else $s = $lng[31];
      mail($username, $zoename, $lng[32] . "\n" . $lng[33] . ': ' . $session[12] . ' %' . "\n" . $lng[34] . ': ' . $s . ' ' . $lng[35] . "\n" . $lng[36] . ': ' . $session[14] . ' km' . "\n" . $lng[37] . ': ' . $session[8] . ' ' . $session[9]);
      $session[5] = 'Y';
    } else if ($session[5] == 'Y' && $session[10] != 1) $session[5] = 'N';
  }
  if ($mail_csf === 'Y') {
    if ($session[6] == 'Y' && $session[10] != 1) mail($username, $zoename, $lng[38] . "\n" . $lng[33] . ': ' . $session[12] . ' %' . "\n" . $lng[36] . ': ' . $session[14] . ' km' . "\n" . $lng[37] . ': ' . $session[8] . ' ' . $session[9]);
    if ($session[10] == 1) $session[6] = 'Y';
    else $session[6] = 'N';
  }

  //Save data in database if configured
  if ($update_sucess === TRUE && $save_in_db === 'Y') {
    if (!file_exists('database.csv')) {
      if ($zoeph == 1) file_put_contents('database.csv', 'Date;Time;Mileage;Outside temperature;Battery temperature;Battery level;Range;Cable status;Charging status;Charging speed;Remaining charging time;Charging schedule' . "\n");
      else file_put_contents('database.csv', 'Date;Time;Mileage;Battery level;Battery capacity;Range;Cable status;Charging status;Charging speed;Remaining charging time;GPS Latitude;GPS Longitude;GPS date;GPS time;Outside temperature;Weather condition;Charging schedule' . "\n");
    }
    if ($zoeph == 1) file_put_contents('database.csv', $session[8] . ';' . $session[9] . ';' . $session[7] . ';' . $session[17] . ';' . $session[13] . ';' . $session[12] . ';' . $session[14] . ';' . $session[11] . ';' . $session[10] . ';' . $session[16] . ';' . $session[15] . ';' . $session[24] . "\n", FILE_APPEND);
    else file_put_contents('database.csv', $session[8] . ';' . $session[9] . ';' . $session[7] . ';' . $session[12] . ';' . $session[13] . ';' . $session[14] . ';' . $session[11] . ';' . $session[10] . ';' . $session[16] . ';' . $session[15] . ';' . $session[17] . ';' . $session[18] . ';' . $session[19] . ';' . $session[20] . ';' . $session[22] . ';' . $session[23] . ';' . $session[24] . "\n", FILE_APPEND);
  }
}
curl_close($ch);

//Cache data
if (($md5 != $session[3] && $update_sucess === TRUE) || $cmd_cron === TRUE || is_numeric($_POST['bl'])) {
  $session[3] = $md5;
  $session[4] = $timestamp_now;
  $sessionstr = implode('|', $session);
  file_put_contents('api-session', $sessionstr);
}

//Output  
$data = (object) [
  "scriptTime"      => $iso_now,
  "hash"            => $session[3],
  "timestamp"       => $session[4],
  "isCharging"      => $session[6],
  "mileage"         => $session[7],
  "updateDate"      => $session[8],
  "updateTime"      => $session[9],
  "chargingStatus"  => $session[10],
  "cableStatus"     => $session[11],
  "batteryLevel"    => $session[12],
  "batteryCapacity" => $session[13],
  "range"           => $session[14],
  "chargingTime"    => $session[15],
  "chargingEffect"  => $session[16],
  "updateSuccess"   => $update_sucess,
  "response"        => $update_sucess ? "" : $response,
  "lastUpdateString"=> "{$session[8]}T{$session[9]}+01:00",
  "location" => (object) [
    "lat"       => $session[17],
    "lon"       => $session[18],
    "date"      => $session[19],
    "time"      => $session[20],
    "asString" => "{$session[17]},{$session[18]}"
  ]
];

echo json_encode($data);
