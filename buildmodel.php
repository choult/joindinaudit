<?php

namespace Crell\Joinin;

require 'vendor/autoload.php';

function buildModel($targetTags = ['php'])
{
    $conn = getDb();
    $stopwords = ['as','up','of','s','us','and','our','a','the','','we','is','for','you','in','this'];

    $terms = [];

    // Get items with tags
    $result = $conn->executeQuery('SELECT * FROM event WHERE tags != ""');
    foreach ($result as $event) {
        $tags = explode(',', preg_replace('/\s/', '', $event['tags']));
        $positive = (array_intersect($tags, $targetTags)) ? true : false;

        // Tokenize
        $words = tokenize(
            \strtolower("{$event['name']} {$event['description']}"),
            $stopwords
        );

        // For each token, store
        foreach ($words as $word => $df) {
            if (!isset($terms[$word])) {
                $terms[$word] = array_fill_keys($targetTags, ['pos' => 0, 'neg' => 0, 'freq' => 0, 'docs' => 0]);
                $terms[$word]['__docs'] = 0;
                $terms[$word]['__freq'] = 0;
            }
            $terms[$word]['__docs']++;
            $terms[$word]['__freq'] += $df;

            foreach ($targetTags as $tag) {
                $terms[$word][$tag][($positive) ? 'pos' : 'neg']++;
            }
        }
    }

    // Work out weightings
    var_dump($terms);
}

function tokenize($str, array $stopwords = []) {
    $str = preg_replace('/\'/', '', $str);
    $str = preg_replace('/[\W0-9]+/', ' ', $str); // all word boundaries to space
    $words = preg_split('/\W+/', $str);
    $words = array_diff($words, $stopwords);
    $counts = array_fill_keys(array_unique($words), 0);
    foreach ($words as $word) {
        $counts[$word]++;
    }
    return $counts;
}

buildModel();