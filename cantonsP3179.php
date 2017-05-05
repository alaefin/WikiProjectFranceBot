<?php
if ( PHP_SAPI !== 'cli' ) {
    echo "This script takes long to process, it should run on the command line, exiting...\n";
    return;
}

$config = \json_decode( \file_get_contents( __DIR__ . '/config.WikiProjectFranceBot.json' ), true );

require __DIR__ . '/WikidataRollBot/vendor/autoload.php';

$api = new \GuzzleHttp\Client( [ 'base_uri' => 'https://www.wikidata.org/', 'cookies' => true, 'headers' => [ 'User-Agent' => 'RollBot v0.1, by [[:fr:User:Alphos]]' ] ] );

// Easy API response formatting, user-account edit assertion
$format = [ 'format' => 'json', 'formatversion' => 2 ];
/********************
*       Login       *
********************/
// First, get the login token by attempting to log in
$lgtokenRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
    'form_params' => $config['lg'] + [ 'action' => 'login' ] + $format
] )->getBody(), true );
if ( !isset( $lgtokenRequest['login']['token'] ) ) {
    echo "Invalid format for the first login attempt, exiting...\n";
    return;
}
$lgtoken = $lgtokenRequest['login']['token'];
// Then send the actual login request with the login token
$api->request( 'POST', 'w/api.php', [
    'form_params' => $config['lg'] + [ 'lgtoken' => $lgtoken ] + [ 'action' => 'login' ] + $format
] )->getBody();
// Finally check the login was indeed successful by matching the username the server
// acknowleges with the username that was provided for login
$loginCheckRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
    'form_params' => [ 'action' => 'query', 'meta' => 'userinfo' ] + $format
] )->getBody(), true );
if ( !isset( $loginCheckRequest['query']['userinfo']['name'] ) || ( $loginCheckRequest['query']['userinfo']['name'] !== $config['lg']['lgname'] ) ) {
    echo "Login unsuccessful, exiting...\n";
    return;
}
echo "Logged in as {$config['lg']['lgname']}\n";
// Setting an additional bit so requests from now on will be assumed as performed while logged in
$format['assert'] = 'user';
/**************************
* Acquiring an edit token *
**************************/
$editTokenRequest = \json_decode( $x = $api->request( 'POST', 'w/api.php', [
    'form_params' => [
        'action' => 'query',
        'meta' => 'tokens'
    ] + $format
] )->getBody(), true );
if ( !isset( $editTokenRequest['query']['tokens']['csrftoken'] ) ) {
    echo "Couldn't acquire an edit token, exiting...\n";
    return;
}
$editToken = $editTokenRequest['query']['tokens']['csrftoken'];
echo "Edit token acquired : $editToken\n";

$sparQLClient = new \GuzzleHttp\Client( [ 'base_uri' => 'https://query.wikidata.org', 'headers' => [ 'User-Agent' => 'RollBot v0.1, by [[:fr:User:Alphos]]', 'Accept' => 'application/sparql-results+json', 'Content-Type' => 'application/sparql-query' ] ] );

// Get a list of old and new cantons, in order to add a P794("as") qualifier
$oldCantonsSparQL = <<<OLDCANTONS
SELECT DISTINCT ?oldcanton WHERE {
  ?oldcanton wdt:P31 wd:Q184188 .
} ORDER BY ?oldcanton
OLDCANTONS;
$oldCantons = \array_map( function( $s ) { return \str_replace( 'http://www.wikidata.org/entity/', '', $s ); }, \array_column( \array_column( \json_decode( $sparQLClient->request( 'POST', '/sparql', [ 'form_params' => ['query' => $oldCantonsSparQL, 'format' => 'json' ] ] )->getBody(), true )['results']['bindings'], 'oldcanton' ), 'value' ) );
echo \count($oldCantons), " anciens cantons\n";

$newCantonsSparQL = <<<NEWCANTONS
SELECT DISTINCT ?newcanton WHERE {
  ?newcanton wdt:P31 wd:Q18524218 .
} ORDER BY ?newcanton
NEWCANTONS;
$newCantons = \array_map( function( $s ) { return \str_replace( 'http://www.wikidata.org/entity/', '', $s ); }, \array_column( \array_column( \json_decode( $sparQLClient->request( 'POST', '/sparql', [ 'form_params' => ['query' => $newCantonsSparQL, 'format' => 'json' ] ] )->getBody(), true )['results']['bindings'], 'newcanton' ), 'value' ) );
echo count($newCantons), " nouveaux cantons\n";

$communesSparQL = <<<COMMUNES
SELECT DISTINCT ?commune ?canton ?qualProp ?time ?precision ?timezone ?calendar WHERE {
  ?commune p:P31/ps:P31/wdt:P279* wd:Q484170 .
  ?commune p:P131 ?cantonStmt .
  ?cantonStmt ps:P131 ?canton .
  ?canton wdt:P31 ?cantonType .
  VALUES ?cantonType { wd:Q18524218 wd:Q184188 } .
  OPTIONAL {
    ?cantonStmt ?qualifier ?qualVal .
    ?qualProp wikibase:qualifierValue ?qualifier .
    ?qualVal wikibase:timePrecision ?precision ;
             wikibase:timeValue ?time ;
  	         wikibase:timeTimezone ?timezone ;
             wikibase:timeCalendarModel ?calendar ;
  }
}
ORDER BY ASC(?commune) ASC(?canton)
COMMUNES;
$communesSparQLResponse = \json_decode( $sparQLClient->request( 'POST', '/sparql', [ 'form_params' => ['query' => $communesSparQL, 'format' => 'json' ] ] )->getBody(), true )['results']['bindings'];
$communes = [];
foreach ( $communesSparQLResponse as $commune ) {
	if ( !isset( $communes[ \str_replace( 'http://www.wikidata.org/entity/', '', $commune['commune']['value'] ) ] ) ) {
		$communes[ \str_replace( 'http://www.wikidata.org/entity/', '', $commune['commune']['value'] ) ] = [];
	}
	if ( !isset( $communes[ \str_replace( 'http://www.wikidata.org/entity/', '', $commune['commune']['value'] ) ][ \str_replace( 'http://www.wikidata.org/entity/', '', $commune['canton']['value'] ) ] ) ) {
		$communes[ \str_replace( 'http://www.wikidata.org/entity/', '', $commune['commune']['value'] ) ][ \str_replace( 'http://www.wikidata.org/entity/', '', $commune['canton']['value'] ) ] = [];
	}
	if ( !empty( $commune['qualProp'] ) ) {
		$communes[ \str_replace( 'http://www.wikidata.org/entity/', '', $commune['commune']['value'] ) ][ \str_replace( 'http://www.wikidata.org/entity/', '', $commune['canton']['value'] ) ][] = [ \str_replace( 'http://www.wikidata.org/entity/', '', $commune['qualProp']['value'] ), \json_encode( 
			[
				'time' => $commune['time']['value'],
				'timezone' => $commune['timezone']['value'],
				'before' => 0,
				'after' => 0,
				'precision' => $commune['precision']['value'],
				'calendarmodel' => $commune['calendar']['value']
			]
		) ];
	}
}

foreach ( $communes as $communeQid => $commune ) {
	foreach ( $commune as $cantonQid => $communeCantonQualifiers ) {
		$cantonCommuneClaim = \json_decode( $api->request( 'POST', 'w/api.php', [ 'form_params' =>
			[
				'action' => 'wbcreateclaim',
				'entity' => $commune,
				'property' => 'P3179',
				'snaktype' => 'value',
				'value' => '{"entity-type":"item","numeric-id":' . ltrim( $cantonQid, 'Q' ) . '}',
				'bot' => 1,
			] + $format + $editToken ] ), true ) ;
		if ( ( isset( $cantonCommuneClaim['success'] ) ) && ( $cantonCommuneClaim['success'] !== 1 ) ) {
			$cantonCommuneClaimId = $cantonCommuneClaim['claim']['id'];
		}
		else {
			\file_put_contents( __DIR__ . '/cantonsP3179.error.log', "claim failed : {$communeQid}\t{$cantonQid}\t{$cantonCommuneClaim['error']['info']}\n", \FILE_APPEND );
		}
		foreach ( $communeCantonQualifiers as $qualifier ) {
			// if ( \in_array( $qualifier[0], [ 'P580', 'P582' ] ) ) {
				// $qualifier[1] = 
			// }
			$cantonCommuneQualifier = \json_decode( $api->request( 'POST', 'w/api.php', [ 'form_params' => 
				[
					'action' => 'wbsetqualifier',
					'claim' => $cantonCommuneClaimId,
					'property' => $qualifier[0],
					'value' => $qualifier[1],
					'snaktype' => 'value',
					'bot' => 1
				] + $format + $editToken ] ), true );
			if ( ( !isset( $cantonCommuneQualifier['success'] ) ) || ( $cantonCommuneQualifier['success'] !== 1 ) ) {
				\file_put_contents( __DIR__ . '/cantonsP3179.error.log', "qualifier failed : {$cantonCommuneClaimId}\t{$qualifier[0]}\t{$qualifier[1]}\n", \FILE_APPEND );
			}
		}
		$cantonCommuneAs = \json_decode( $api->request( 'POST', 'w/api.php', [ 'form_params' =>
			[
				'action' => 'wbsetqualifier',
				'claim' => $cantonCommuneClaimId,
				'property' => 'P794',
				'value' => '{"entity-type":"item","numeric-id":' . ( ( \in_array( $cantonQid, $newCantons ) ) ? '18524218' : '184188' ) . '}'
			] + $format + $editToken ] ), true );
		if ( ( !isset( $cantonCommuneAs['success'] ) ) || ( $cantonCommuneAs['success'] !== 1 ) ) {
			\file_put_contents( __DIR__ . '/cantonsP3179.error.log', "qualifier failed : {$cantonCommuneClaimId}\tP794\tQ" . ( ( \in_array( $cantonQid, $newCantons ) ) ? '18524218' : '184188' ) . "\n", \FILE_APPEND );
		}
	}
}