<?php

if ( PHP_SAPI !== 'cli' ) {
    echo "This script takes long to process, it should run on the command line, exiting...\n";
    return;
}

$config = \json_decode( \file_get_contents( __DIR__ . '/config.WikiProjectFranceBot.json' ), true );

require __DIR__ . '/vendor/autoload.php';

$api = new \GuzzleHttp\Client( [ 'base_uri' => 'https://www.wikidata.org/', 'cookies' => true, 'headers' => [ 'User-Agent' => 'WikiProjectFranceBot, by [[:d:User:Alphos]]' ] ] );

// Easy API response formatting, user-account edit assertion
$format         = [ 'format' => 'json', 'formatversion' => 2 ];
/* * ******************
 *       Login       *
 * ****************** */
// First, get the login token by attempting to log in
$lgtokenRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
            'form_params' => $config[ 'lg' ] + [ 'action' => 'login' ] + $format
        ] )->getBody(), true );
if ( !isset( $lgtokenRequest[ 'login' ][ 'token' ] ) ) {
    echo "Invalid format for the first login attempt, exiting...\n";
    return;
}
$lgtoken           = $lgtokenRequest[ 'login' ][ 'token' ];
// Then send the actual login request with the login token
$api->request( 'POST', 'w/api.php', [
    'form_params' => $config[ 'lg' ] + [ 'lgtoken' => $lgtoken ] + [ 'action' => 'login' ] + $format
] )->getBody();
// Finally check the login was indeed successful by matching the username the server
// acknowleges with the username that was provided for login
$loginCheckRequest = \json_decode( $api->request( 'POST', 'w/api.php', [
            'form_params' => [ 'action' => 'query', 'meta' => 'userinfo' ] + $format
        ] )->getBody(), true );
if ( !isset( $loginCheckRequest[ 'query' ][ 'userinfo' ][ 'name' ] ) || ( $loginCheckRequest[ 'query' ][ 'userinfo' ][ 'name' ] !== $config[ 'lg' ][ 'lgname' ] ) ) {
    echo "Login unsuccessful, exiting...\n";
    return;
}
echo "Logged in as {$config[ 'lg' ][ 'lgname' ]}\n";
// Setting an additional bit so requests from now on will be assumed as performed while logged in
$format[ 'assert' ] = 'user';
/* * ************************
 * Acquiring an edit token *
 * ************************ */
$editTokenRequest = \json_decode( $x                = $api->request( 'POST', 'w/api.php',
                [
            'form_params' => [
        'action' => 'query',
        'meta'   => 'tokens'
            ] + $format
        ] )->getBody(), true );
if ( !isset( $editTokenRequest[ 'query' ][ 'tokens' ][ 'csrftoken' ] ) ) {
    echo "Couldn't acquire an edit token, exiting...\n";
    return;
}
$editToken = $editTokenRequest[ 'query' ][ 'tokens' ][ 'csrftoken' ];
echo "Edit token acquired : $editToken\n";

/* * *************************
 * Getting lists of cantons *
 * ************************* */
$sparQLClient = new \GuzzleHttp\Client( [ 'base_uri' => 'https://query.wikidata.org', 'headers' => [ 'User-Agent' => 'RollBot v0.1, by [[:fr:User:Alphos]]', 'Accept' => 'application/sparql-results+json',
        'Content-Type' => 'application/sparql-query' ] ] );

// Get a list of old and new cantons, in order to add a P794("as") qualifier
$oldCantonsSparQL = <<<OLDCANTONS
SELECT DISTINCT ?oldcanton WHERE {
  ?oldcanton wdt:P31 wd:Q184188 .
} ORDER BY ?oldcanton
OLDCANTONS;
$oldCantons       = \array_map( function( $s ) {
    return \str_replace( 'http://www.wikidata.org/entity/', '', $s );
},
        \array_column( \array_column( \json_decode( $sparQLClient->request( 'POST', '/sparql',
                                        [ 'form_params' => ['query' => $oldCantonsSparQL, 'format' => 'json' ] ] )->getBody(), true )[ 'results' ][ 'bindings' ],
                        'oldcanton' ), 'value' ) );
echo \count( $oldCantons ), " anciens cantons\n";

$newCantonsSparQL = <<<NEWCANTONS
SELECT DISTINCT ?newcanton WHERE {
  ?newcanton wdt:P31 wd:Q18524218 .
} ORDER BY ?newcanton
NEWCANTONS;
$newCantons       = \array_map( function( $s ) {
    return \str_replace( 'http://www.wikidata.org/entity/', '', $s );
},
        \array_column( \array_column( \json_decode( $sparQLClient->request( 'POST', '/sparql',
                                        [ 'form_params' => ['query' => $newCantonsSparQL, 'format' => 'json' ] ] )->getBody(), true )[ 'results' ][ 'bindings' ],
                        'newcanton' ), 'value' ) );
echo count( $newCantons ), " nouveaux cantons\n";

/* * ********************************************************************************
 * Getting the list of communes and the cantons they're linked to, with qualifiers *
 * ******************************************************************************** */
$communesSparQL         = <<<COMMUNES
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
$communesSparQLResponse = \json_decode( $sparQLClient->request( 'POST', '/sparql', [ 'form_params' => ['query' => $communesSparQL, 'format' => 'json' ] ] )->getBody(),
                true )[ 'results' ][ 'bindings' ];

/* * *
 * Getting a formatted list of commune-canton associations with qualifiers
 */
$communes = [ ];
foreach ( $communesSparQLResponse as $commune ) {
    // If the commune isn't already in the formatted list, add it to the list as a key to a new element
    if ( !isset( $communes[ \str_replace( 'http://www.wikidata.org/entity/', '', $commune[ 'commune' ][ 'value' ] ) ] ) ) {
        $communes[ \str_replace( 'http://www.wikidata.org/entity/', '', $commune[ 'commune' ][ 'value' ] ) ] = [ ];
    }
    // If for the current commune, the canton isn't already associated, add it to the element of that commune, as a key to a new element
    if ( !isset( $communes[ \str_replace( 'http://www.wikidata.org/entity/', '', $commune[ 'commune' ][ 'value' ] ) ][ \str_replace( 'http://www.wikidata.org/entity/',
                            '', $commune[ 'canton' ][ 'value' ] ) ] ) ) {
        $communes[ \str_replace( 'http://www.wikidata.org/entity/', '', $commune[ 'commune' ][ 'value' ] ) ][ \str_replace( 'http://www.wikidata.org/entity/',
                        '', $commune[ 'canton' ][ 'value' ] ) ] = [ ];
    }
    // If for the current commune-canton association, there is a qualifier, add it as a new element (an array of the form [Property,Value], in order to
    // avoid overwriting previous qualifiers with the same property).
    // Currently the qualifiers are only time-related, so it should be reasonably safe to consider them as such
    if ( !empty( $commune[ 'qualProp' ] ) ) {
        $communes[ \str_replace( 'http://www.wikidata.org/entity/', '', $commune[ 'commune' ][ 'value' ] ) ][ \str_replace( 'http://www.wikidata.org/entity/',
                        '', $commune[ 'canton' ][ 'value' ] ) ][] = [ \str_replace( 'http://www.wikidata.org/entity/', '', $commune[ 'qualProp' ][ 'value' ] ), \json_encode(
                    [
                        'time'          => $commune[ 'time' ][ 'value' ],
                        'timezone'      => $commune[ 'timezone' ][ 'value' ],
                        'before'        => 0,
                        'after'         => 0,
                        'precision'     => $commune[ 'precision' ][ 'value' ],
                        'calendarmodel' => $commune[ 'calendar' ][ 'value' ]
                    ]
            ) ];
    }
}
// Getting rid of the now unnecessary var, to preserve memory
unset( $communesSparQLResponse );

/****************************************************************************************************************
* Adding the statements and qualifiers that were found, and adding a P794 qualifier to the new P3179 statements *
****************************************************************************************************************/
// Each key in the $communes list is a unique Qid of a commune
foreach ( $communes as $communeQid => $commune ) {
    // Each key in the $commune array is a unique Qid of a canton
    foreach ( $commune as $cantonQid => $communeCantonQualifiers ) {
        // Adding the new P3179 statement for that commune and that canton
        $cantonCommuneClaim = \json_decode( $api->request( 'POST', 'w/api.php',
            [ 'form_params' =>
                [
                    'action'   => 'wbcreateclaim',
                    'entity'   => $commune,
                    'property' => 'P3179',
                    'snaktype' => 'value',
                    'value'    => '{"entity-type":"item","numeric-id":' . ltrim( $cantonQid, 'Q' ) . '}',
                    'bot'      => 1,
                ] + $format + $editToken
            ] ), true );
        // Getting the new claim id
        if ( ( isset( $cantonCommuneClaim[ 'success' ] ) ) && ( $cantonCommuneClaim[ 'success' ] === 1 ) ) {
            $cantonCommuneClaimId = $cantonCommuneClaim[ 'claim' ][ 'id' ];
        }
        else {
            // In case of failure, don't try and add qualifiers, log and skip that statement entirely
            \file_put_contents( __DIR__ . '/cantonsP3179.error.log', "claim failed : {$communeQid}\t{$cantonQid}\t{$cantonCommuneClaim[ 'error' ][ 'info' ]}\n",
                    \FILE_APPEND );
            continue;
        }
        foreach ( $communeCantonQualifiers as $qualifier ) {
            // Each element in the $communeCantonQualifiers list is a [Property,Value] array (two elements, [0] and [1], not key => value)
            // Adding the qualifier to the new claim, by claim id
            $cantonCommuneQualifier = \json_decode( $api->request( 'POST', 'w/api.php',
                [ 'form_params' =>
                    [
                        'action'   => 'wbsetqualifier',
                        'claim'    => $cantonCommuneClaimId,
                        'property' => $qualifier[ 0 ],
                        'value'    => $qualifier[ 1 ],
                        'snaktype' => 'value',
                        'bot'      => 1
                    ] + $format + $editToken 
                ] ), true );
            if ( ( !isset( $cantonCommuneQualifier[ 'success' ] ) ) || ( $cantonCommuneQualifier[ 'success' ] !== 1 ) ) {
                \file_put_contents( __DIR__ . '/cantonsP3179.error.log', "qualifier failed : {$cantonCommuneClaimId}\t{$qualifier[ 0 ]}\t{$qualifier[ 1 ]}\n",
                        \FILE_APPEND );
            }
        }
        // Finally add a P794 qualifier to the new claim
        $cantonCommuneAs = \json_decode( $api->request( 'POST', 'w/api.php',
                        [ 'form_params' =>
                    [
                        'action'   => 'wbsetqualifier',
                        'claim'    => $cantonCommuneClaimId,
                        'property' => 'P794',
                        'value'    => '{"entity-type":"item","numeric-id":' . ( ( \in_array( $cantonQid, $newCantons ) ) ? '18524218' : '184188' ) . '}'
                    ] + $format + $editToken ] ), true );
        if ( (!isset( $cantonCommuneAs[ 'success' ] ) ) || ( $cantonCommuneAs[ 'success' ] !== 1 ) ) {
            \file_put_contents( __DIR__ . '/cantonsP3179.error.log',
                    "qualifier failed : {$cantonCommuneClaimId}\tP794\tQ" . ( ( \in_array( $cantonQid, $newCantons ) ) ? '18524218' : '184188' ) . "\n",
                    \FILE_APPEND );
        }
    }
}
