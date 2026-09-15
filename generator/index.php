<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use App\AppExtension;
use App\ConfigLoader;
use App\GitHubClient;
use App\ProfileGeneratorCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;

$projectDir = __DIR__;
$token = getenv('GITHUB_TOKEN') ?: null;

$headers = [
    'Accept' => 'application/vnd.github+json',
    'User-Agent' => 'profile-generator',
    'X-GitHub-Api-Version' => '2022-11-28',
];

if (null !== $token) {
    $headers['Authorization'] = sprintf('Bearer %s', $token);
}

$http = new RetryableHttpClient(
    HttpClient::create(['timeout' => 10, 'max_duration' => 30, 'headers' => $headers]),
    new GenericRetryStrategy(),
    3
);

$twig = new Environment(new FilesystemLoader($projectDir.'/templates'), [
    'cache' => sys_get_temp_dir().'/profile-generator/twig',
    'strict_variables' => true,
]);
$twig->addExtension(new AppExtension());
$twig->addExtension(new IntlExtension());

$app = new Application('profile-generator', '1.0.0');
$app->addCommand(new ProfileGeneratorCommand(
    new GitHubClient($http),
    $twig,
    new ConfigLoader($projectDir),
    new Filesystem(),
    authenticated: null !== $token,
));
$app->setDefaultCommand('generate', true);
$app->run();
