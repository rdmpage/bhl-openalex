<?php
// Download all OpenAlex works that have a location in BHL (S4306402618)
// Output is JSON Lines (one work per line) on stdout, progress on stderr.
//
// Usage: php bhl_openalex.php > bhl_works.jsonl

if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include 'env.php';
}

$api_key = getenv('OPENALEX_APIKEY');

if ($api_key == '')
{
	fwrite(STDERR, "Usage: php bhl_openalex.php YOUR_API_KEY > bhl_works.jsonl\n");
	exit(1);
}

$filter = 'locations.source.id:S4306402618';
$select = 'id,ids,doi,display_name,publication_year,type,biblio,primary_location,locations';

$cursor = '*';
$count  = 0;

while ($cursor)
{
	$url = 'https://api.openalex.org/works'
		. '?filter=' . $filter
		. '&select=' . $select
		. '&per_page=100'
		. '&cursor=' . urlencode($cursor)
		. '&api_key=' . urlencode($api_key);

	$json = get_json($url);

	if (!$json)
	{
		fwrite(STDERR, "Giving up at cursor $cursor\n");
		exit(1);
	}

	foreach ($json->results as $work)
	{
		echo json_encode($work, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		$count++;
	}

	fwrite(STDERR, $count . ' / ' . $json->meta->count . "\n");

	$cursor = $json->meta->next_cursor;
}

//----------------------------------------------------------------------------------------
// Fetch a URL and decode JSON, retrying with backoff on 429 / 5xx / network errors
function get_json($url, $max_tries = 5)
{
	$context = stream_context_create(array(
		'http' => array(
			'ignore_errors' => true,
			'timeout'       => 60
		)
	));

	$wait = 2;

	for ($try = 1; $try <= $max_tries; $try++)
	{
		$body = @file_get_contents($url, false, $context);

		$status = 0;
		if (isset($http_response_header[0]))
		{
			preg_match('/\s(\d{3})\s/', $http_response_header[0], $m);
			$status = (int)$m[1];
		}

		if ($body !== false && $status == 200)
		{
			return json_decode($body);
		}

		fwrite(STDERR, "HTTP $status, retrying in {$wait}s (try $try)\n");
		sleep($wait);
		$wait *= 2;
	}

	return null;
}
