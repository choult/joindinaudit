<?php

namespace Crell\Joinin;

require 'vendor/autoload.php';

function buildModel(array $targetTags = ['php'], array $stopwords = [])
{
    $conn = getDb();

    $terms = [];

    // Get items with tags
    $result = $conn->executeQuery('SELECT * FROM event WHERE tags != ""');
    foreach ($result as $event) {
        $tags = explode(',', preg_replace('/\s/', '', $event['tags']));
        $positive = (array_intersect($tags, $targetTags)) ? true : false;

        // Tokenize
        $words = tokenize(
            "{$event['name']} {$event['description']}",
            $stopwords
        );

        // For each token, store
        foreach ($words as $word => $df) {
            if (!isset($terms[$word])) {
                $terms[$word] = array_fill_keys($targetTags, ['pos' => 0, 'neg' => 0]);
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

    foreach ($terms as $word => $term) {
        $docs = $term['__docs'];
        foreach ($targetTags as $tag) {
            $terms[$word][$tag]['score'] = ($term[$tag]['pos'] - $term[$tag]['neg']) / $docs;
        }
    }

    return $terms;
}

function applyModel(array $model, array $event, $threshold = 0.7, array $targetTags = ['php'], array $stopwords = [])
{
    $words = tokenize("{$event['name']} {$event['description']}");
    $tagScores = array_fill_keys($targetTags, 0);
    foreach ($words as $word => $count) {
        if (isset($model[$word])) {
            foreach ($targetTags as $tag) {
                $tagScores[$tag] += ($count) * $model[$word][$tag]['score'];
            }
        }
    }

    $tags = array_keys(array_filter($tagScores, function($item) use ($threshold) {
        return ($item >= $threshold);
    }));

    return $tags;
}

function calculateConfidence($model, $threshold = 0.7, array $targetTags = ['php'], array $stopwords = [])
{
    $conn = getDb();

    $result = $conn->executeQuery('SELECT * FROM event');
    foreach ($result as $event) {
        $tags = applyModel($model, $event, $threshold, $targetTags, $stopwords);
        print $event['name'] . ' found: ' . $event['tags'] . '; calculated: ' . implode(',', $tags) . "\n";
    }
}

function tokenize($str, array $stopwords = []) {
    $str = preg_replace('/\'/', '', \strtolower($str));
    $str = preg_replace('/[\W0-9]+/', ' ', $str); // all word boundaries to space
    $words = preg_split('/\W+/', $str);
    $words = array_diff($words, $stopwords);
    $counts = array_fill_keys(array_unique($words), 0);
    foreach ($words as $word) {
        $counts[$word]++;
    }
    return $counts;
}

$threshold = 0.7;
$targetTags = ['php'];
$stopwords = ['as','up','of','s','us','and','our','a','the','','we','is','for','you','in','this'];
$model = buildModel($targetTags, $stopwords);
calculateConfidence($model, $threshold, $targetTags, $stopwords);