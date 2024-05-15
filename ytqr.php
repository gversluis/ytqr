<?php
	declare(strict_types=1);
	include('config.php');
	global $ytqrShortMapCache;
	handleRequest();

	function handleRequest():void {
		if (array_key_exists('playlists', $_GET)) {
			$service = getGoogleService();
			$playlists = $service->playlists->listPlaylists('snippet', [ 'mine' => true, 'maxResults' => $_GET['playlists'] ]);
      $urls = getUrlsFromPlaylists($playlists);
			print json_encode([ 'playlists' => $playlists, 'urls' => $urls ]);
			/*
			print_r($playlists->getPageInfo());
			print_r($playlists->getItems());
		 */
		}

		if (array_key_exists('playlist', $_GET)) {
			$service = getGoogleService();
			$playlistItems = $service->playlistItems->listPlaylistItems('snippet,contentDetails', [ 'playlistId' => $_GET['playlist'], 'maxResults' => 50, ]);
      $urls = getUrlsFromPlaylist($playlistItems);
			print json_encode([ 'playlist' => $playlistItems, 'urls' => $urls ]);
		}

		if (array_key_exists('short', $_GET)) {
			$longUrl = $_GET['short'];
			if ($longUrl && str_contains($longUrl, ':') ) {
				$description = $_GET['description'];
				$shortUrl = getShort($longUrl);
				if (!$shortUrl && $description) $shortUrl = generateShort($longUrl, $description);	# we prefer a description for human readability :)
				print json_encode([ 'shortUrl' => $shortUrl, 'longUrl' => $longUrl, 'description' => $description]);
			} else {
				print json_encode([ 'shortUrl' => '', 'longUrl' => '', 'description' => '', 'error' => 'Append URL to short to request' ]);
			}
		}

		if (array_key_exists('redirect', $_GET)) {
			$longUrl = getLong($_GET['redirect']);
			if ($longUrl) header('Location: ' . $longUrl, true, 302);
			print "You automatically should have been redirected to $longUrl";
		}
	}

	/* helper functions */

	function getGoogleService():Google_Service_YouTube {
		$client = new Google_Client();
		$client->setAuthConfig( YTQR_AUTH_CONFIG_FILE );
		$client->setAccessType('offline');
		$refreshToken = file_get_contents( YTQR_REFRESH_TOKEN_FILE );
		$accessToken = $client->fetchAccessTokenWithRefreshToken( $refreshToken );
		if (array_key_exists('error', $accessToken)) throw new Exception($accessToken['error'] .': '. $accessToken['error_description']);
		$client->setAccessToken($accessToken);
		return new Google_Service_YouTube($client);
	}

	function saveShort(string $short, string $target, string $description) {
    // open file append
    if (is_writable(YTQR_SHORT_MAP)) {
      if (!$fp = fopen(YTQR_SHORT_MAP, "a")) { // "If stream was fopen()ed in append mode, fwrite()s are atomic (unless the size of data exceeds the filesystem's block size, on some platforms, and as long as the file is on a local filesystem). That is, there is no need to flock() a resource before calling fwrite(); all of the data will be written without interruption.", https://www.php.net/manual/en/function.fwrite.php
        trigger_error("Failed to open file " . YTQR_SHORT_MAP);
      } else {
        if (fwrite($fp, "\n$short $target # $description") === FALSE) {
          trigger_error("Failed to write to file " . YTQR_SHORT_MAP);
        }
        fclose($fp);
      }
    }
	}

	function generateShort(string $target, string $description):string {
		$tries = 300000;	// 300.000 takes about 3 seconds on a pi4 4GB
		$shortUrlCharacters = YTQR_SHORT_CHARACTERS;
		$shortUrls = getShortMap();
		$shortUrl = null;
		do {
			if ($tries-- <= 0) throw new Exception('Could not create unique shortURL, most combinations are taken or is it just bad luck?');
			shuffle($shortUrlCharacters);
			$shortUrl = $shortUrlCharacters[0].$shortUrlCharacters[1].$shortUrlCharacters[2];
		} while(array_key_exists($shortUrl, $shortUrls));
		// TODO: save shortUrl
		saveShort($shortUrl, $target, $description);
		$newShortUrl = YTQR_SHORT_BASE_URL . $shortUrl;
		return $newShortUrl;
  }

	function getShortMapText():string {
		global $ytqrShortMapCache;
		if (!$ytqrShortMapCache) $ytqrShortMapCache = file_get_contents( YTQR_SHORT_MAP );
		return $ytqrShortMapCache;
	}

	function getShortMap():array {
		$ytqrShortMapText = getShortMapText();
		preg_match_all("/^([^\s#]+?)[ \t]+(\S+)([ \t]*)(.*?)$/m", $ytqrShortMapText, $matches); 
		return array_combine($matches[1], $matches[2]);
	}

	function getShort(string $longUrl):?string {
		$ytqrShortMapText = getShortMapText();
		$quotedLongUrl = preg_quote($longUrl);
		$quotedLongUrl = str_replace("/", "\/", $quotedLongUrl);	// Note that / is not a special regular expression character. https://www.php.net/manual/en/function.preg-quote.php
		preg_match_all("/^([^\s#]+?)[ \t]+($quotedLongUrl)([ \t]*)(.*?)$/m", $ytqrShortMapText, $matches); 
		return sizeof($matches[1]) > 0 ? YTQR_SHORT_BASE_URL . $matches[1][0] : null;
	}

	function getLong(string $shortUrl):?string {
		$ytqrShortMapText = getShortMapText();
		$quotedShortUrl = preg_quote($shortUrl);
		$quotedShortUrl = str_replace("/", "\/", $quotedShortUrl);	// Note that / is not a special regular expression character. https://www.php.net/manual/en/function.preg-quote.php
		preg_match_all("/^$quotedShortUrl([ \t]+)([^\s]*)/m", $ytqrShortMapText, $matches);
		return sizeof($matches[2]) > 0 ? $matches[2][0] : null;
	}

  function getUrlsFromPlaylists($playlists) {
    $urls = [];
    $playlistItems = $playlists->getItems();
    foreach($playlistItems as $key => $playlist) {
      $playUrl = sprintf(YTQR_PLAY_PLAYLIST_BASE_URL, $playlist->getId());
      $short = getShort($playUrl);
      $urls[ $playlist->getId() ] = [
        'shortUrl' => $short ? YTQR_SHORT_BASE_URL . $short : null,
        'playUrl' => $playUrl,
      ];
    }
    return $urls;
  }

  function getUrlsFromPlaylist($playlist) {
    $urls = [];
    $playlistItems = $playlist->getItems();
    foreach($playlistItems as $key => $playlistItem) {
      $playUrl = sprintf(YTQR_PLAY_VIDEO_BASE_URL, $playlistItem->getContentDetails()->getVideoId());
      $short = getShort($playUrl);
      $urls[ $playlistItem->getId() ] = [
        'shortUrl' => $short ? YTQR_SHORT_BASE_URL . $short : null,
        'playUrl' => $playUrl,
      ];
    }
    return $urls;
  }

?>	
