#!/usr/bin/env php
<?php
# generate auth config file using oauth credential webservice in Google API console
# download auth config and remove client surrounding object because Google sucks
# Execute from console using "php getRefreshToken.php"

require('config.php');

$client = new Google_Client();
$client->setApplicationName('YTQR');
$client->setScopes([
    'https://www.googleapis.com/auth/youtube.readonly',
]);

$client->setAuthConfig( YTQR_AUTH_CONFIG_FILE );
$client->setAccessType('offline');
// $client->setRedirectUri('https://example.org/123'); // comes from YTQR_AUTH_CONFIG_FILE
// Request authorization from the user.
$client->setPrompt('consent');	// required for refreshToken
$client->setApprovalPrompt("consent");
$client->setIncludeGrantedScopes(true);   // incremental auth
$authUrl = $client->createAuthUrl();
printf("Open this link in your browser:\n%s\n", $authUrl);
print('Enter verification code: ');
$authCode = trim(fgets(STDIN));
// Exchange authorization code for an access token.
$accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
$client->setAccessToken($accessToken);
$refreshToken = $client->getRefreshToken();
print('Write token to '. YTQR_REFRESH_TOKEN_FILE .': '. $refreshToken . "\n");

?>
