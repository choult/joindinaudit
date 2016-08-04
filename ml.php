<?php

require 'vendor/autoload.php';
putenv('joindin_db=mysql://root:root@localhost/joindin');

use Doctrine\DBAL\Connection;
use Choult\Enamel;

$db = getDb();

class Doc implements Enamel\Document
{

	private $tags;

	private $text;

	private $prediction;

	public function __construct(array $tags, $text)
	{
		$this->tags = $tags;
		$this->text = $text;
	}

	public function getLabels()
	{
		return $this->tags;
	}

	public function getContent()
	{
		return $this->text;
	}

	public function setPrediction(array $prediction)
	{
		$this->prediction = $prediction;
	}

	public function getPrediction()
	{
		return $this->prediction;
	}
}

// Load documents

function eventsByTag($tag, $column = 'tags') {
	global $db;
	$stmt = $db->executeQuery(
		"SELECT * FROM event WHERE {$column} REGEXP '[[:<:]]{$tag}[[:>:]]'"
	);

	return $stmt->fetchAll();
}

function eventsWithTags($column = 'tags') {
	global $db;
        $stmt = $db->executeQuery(
                "SELECT * FROM event WHERE {$column} != ''"
        );
        return $stmt->fetchAll();
}

function eventsWithoutTag($tag, $column = 'tags') {
	global $db;
	$stmt = $db->executeQuery(
		"SELECT * FROM event WHERE {$column} NOT REGEXP '[[:<:]]{$tag}[[:>:]]'"
	);
	return $stmt->fetchAll();
}

function eventsWithoutTags($column = 'tags')
{
	global $db;
        $stmt = $db->executeQuery(
                "SELECT * FROM event WHERE {$column} = ''"
        );
        return $stmt->fetchAll();
}

function talksByEvent($event)
{
	global $db;
	$uri = $event['uri'];
	$stmt = $db->executeQuery(
		"SELECT * FROM talk WHERE event_uri = '{$uri}'"
	);

	return $stmt->fetchAll();
}

function getDoc(array $event, array $talks)
{
	$str = $event['name'] . ' ' . $event['description'];
        foreach ($talks as $talk)
        {
                $str .= " {$talk['talk_title']} {$talk['talk_description']}";
        }
        return new Doc(explode(',', $event['tags']), $str);
}

$useLog = false;
$ngram = 1;
$tag = 'php';
$page = 1;
$perPage = 10;
$classifierClass = '\Choult\Enamel\Classifier\MultiVariateNaiveBayes';

$model = new Enamel\Model();
$extractor = new Enamel\Feature\Extractor\NGram('ngram', __DIR__ . '/stopwords.txt' , $ngram);
$classifier = new $classifierClass($extractor, $model);

function trainAndGenerate($classifier, $ngram, $useLog, $tag)
{
	$stats = [];
	$events = eventsWithTags();
	$stats['events'] = count($events);
	
	$memStart = memory_get_peak_usage();
        $start = microtime(true);
	foreach ($events as $event)
	{
		$talks = talksByEvent($event);
		$classifier->train(getDoc($event, $talks));
	}
	$end = microtime(true);
	$memEnd = memory_get_peak_usage();
	$stats['training'] = [
		'time' => ($end - $start),
		'memory' => ($memEnd - $memStart)
	];

	$memStart = memory_get_peak_usage();
	$start = microtime(true);
	$classifier->generateModel();
	$end = microtime(true);
	$memEnd = memory_get_peak_usage();

	$stats['generation'] = [
		'time' => ($end - $start),
                'memory' => ($memEnd - $memStart)
	];
	return $stats;
}

$hash = md5(implode('::', [get_class($classifier), $ngram, (($useLog) ? 1 : 0), $tag]));
$cache = "/tmp/ml-{$hash}";
if (file_exists($cache)) {
	list($model, $stats) = unserialize(file_get_contents($cache));
	$classifier->setModel($model);
} else {
	$stats = trainAndGenerate($classifier, $ngram, $useLog, $tag);
	file_put_contents($cache, serialize([$model, $stats]));
}

$untagged = [];
foreach (array_slice(eventsWithoutTags(), (($page - 1) * $perPage), $perPage) as $event) {
	$talks = talksByEvent($event);
	$doc = getDoc($event, $talks);
	$untagged[] = [$event, $talks, $doc];
	$tags = $classifier->predict($doc, $useLog);
	$doc->setPrediction($tags);
}

?><!DOCTYPE>
<html>
	<head>
		<title>SML</title>
	</head>
	<body>
		<?php
		foreach ($untagged as $ev) {
			$event = $ev[0];
			$talks = $ev[1];
			$doc = $ev[2];	
		?>
		<div class="panel">
			<div class="panel-header"><?php echo $ev[0]['name']; ?></div>
			<div class="panel-body">
				<p><?php echo $event['description']; ?></p>
				<p><strong>Tags:</strong></p>
				<ul class="tags">
					<?php foreach ($doc->getPrediction() as $tag => $score) { ?>
					<li><?php echo "$tag: $score"; ?></li>
					<?php } ?>
				</ul>
			</div>
		</div>
		<?php } ?>	
	</body>
</html>
