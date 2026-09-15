<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class GenerateProfileCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('generate')
            ->setDescription('Generate README.md and README.fr.md from Twig templates')
            ->addArgument('username', InputArgument::REQUIRED, 'GitHub username')
            ->addArgument('output-dir', InputArgument::OPTIONAL, 'Output directory', __DIR__.'/..');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getArgument('username');
        $outputDir = $input->getArgument('output-dir');
        $token = $_ENV['GITHUB_TOKEN'] ?? $_SERVER['GITHUB_TOKEN'] ?? null;

        $config = Yaml::parseFile(__DIR__.'/config.yaml');

        $output->writeln(sprintf('<info>Generating profile for %s...</info>', $username));

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'profile-generator',
        ];

        if ($token) {
            $headers['Authorization'] = sprintf('Bearer %s', $token);
        }

        $client = HttpClient::create(['headers' => $headers]);

        $profile = $this->fetchProfile($client, $username, $output);
        $repos = $this->fetchRepos($client, $username, $output);
        $languages = $this->aggregateLanguages($repos, $config['languages']);
        $blogArticles = $this->fetchBlogArticles($client, $config['blog'], $output);

        $topRepos = array_slice(
            array_filter($repos, fn (array $r) => !$r['fork'] && !$r['archived']),
            0,
            $config['github']['top_repos_count']
        );
        $recentRepos = array_slice($repos, 0, $config['github']['recent_repos_count']);

        $twig = new Environment(new FilesystemLoader(__DIR__));
        $twig->addFilter(new Twig\TwigFilter('truncate', function (?string $value, int $length = 80) {
            if (null === $value) {
                return '';
            }
            if (mb_strlen($value) <= $length) {
                return $value;
            }

            return mb_substr($value, 0, $length).'...';
        }));

        $baseData = [
            'profile' => $profile,
            'repos' => $repos,
            'top_repos' => $topRepos,
            'recent_repos' => $recentRepos,
            'languages' => $languages,
            'socials' => $config['socials'],
            'blog_articles' => $blogArticles,
        ];

        $templates = [
            'en' => ['template' => 'README.md.twig', 'output' => 'README.md'],
            'fr' => ['template' => 'README.fr.md.twig', 'output' => 'README.fr.md'],
        ];

        foreach ($templates as $lang => $tpl) {
            $langConfig = $config[$lang];
            $data = array_merge($baseData, [
                'lang' => $langConfig,
            ]);

            $template = $twig->load($tpl['template']);
            $rendered = $template->render($data);
            file_put_contents($outputDir.'/'.$tpl['output'], $rendered);
            $output->writeln(sprintf('<info>%s generated at %s/%s</info>', strtoupper($lang), $outputDir, $tpl['output']));
        }

        return Command::SUCCESS;
    }

    private function fetchProfile(object $client, string $username, OutputInterface $output): array
    {
        $output->writeln('  Fetching profile...');
        $response = $client->request('GET', sprintf('https://api.github.com/users/%s', $username));

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(sprintf('Failed to fetch profile: %d', $response->getStatusCode()));
        }

        return $response->toArray();
    }

    private function fetchRepos(object $client, string $username, OutputInterface $output): array
    {
        $output->writeln('  Fetching repositories...');
        $repos = [];
        $page = 1;

        do {
            $response = $client->request('GET', sprintf(
                'https://api.github.com/users/%s/repos?per_page=100&sort=pushed&page=%d',
                $username,
                $page
            ));

            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(sprintf('Failed to fetch repos: %d', $response->getStatusCode()));
            }

            $pageRepos = $response->toArray();
            $repos = array_merge($repos, $pageRepos);
            ++$page;
        } while (100 === count($pageRepos));

        $output->writeln(sprintf('  Found %d repositories', count($repos)));

        return $repos;
    }

    private function fetchBlogArticles(object $client, array $blogConfig, OutputInterface $output): array
    {
        $output->writeln('  Fetching blog articles...');
        $articles = ['en' => [], 'fr' => []];
        $baseUrl = $blogConfig['base_url'];
        $maxArticles = $blogConfig['max_articles'];

        foreach ($blogConfig['feeds'] as $lang => $feed) {
            $response = $client->request('GET', $baseUrl.'/'.$feed);

            if (200 !== $response->getStatusCode()) {
                $output->writeln(sprintf('  <warning>Could not fetch %s feed</warning>', $lang));
                continue;
            }

            $feedData = $response->toArray();
            $blogItems = array_filter(
                $feedData['data'] ?? [],
                fn (array $item) => ($item['type'] ?? '') === 'blog' && ($item['attributes']['published'] ?? false)
            );

            usort($blogItems, fn (array $a, array $b) => strcmp($b['attributes']['date'], $a['attributes']['date']));

            foreach (array_slice($blogItems, 0, $maxArticles) as $item) {
                $attrs = $item['attributes'];
                $articles[$lang][] = [
                    'title' => $attrs['title'],
                    'url' => $item['url'],
                    'date' => (new DateTime($attrs['date']))->format('M j, Y'),
                    'tags' => $attrs['tags'] ?? [],
                    'excerpt' => $attrs['excerpt'] ?? '',
                ];
            }
        }

        $output->writeln(sprintf('  Found %d EN articles, %d FR articles', count($articles['en']), count($articles['fr'])));

        return $articles;
    }

    private function aggregateLanguages(array $repos, array $colors): array
    {
        $counts = [];

        foreach ($repos as $repo) {
            if (!isset($repo['language'])) {
                continue;
            }
            $lang = $repo['language'];
            $counts[$lang] = ($counts[$lang] ?? 0) + 1;
        }

        arsort($counts);

        $result = [];
        foreach ($counts as $name => $count) {
            $result[] = [
                'name' => $name,
                'count' => $count,
                'color' => $colors[$name] ?? '555555',
            ];
        }

        return $result;
    }
}

$app = new Application();
$app->add(new GenerateProfileCommand());
$app->run();
