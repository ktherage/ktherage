<?php

declare(strict_types=1);

namespace App;

use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class ProfileGenerator
{
    public function __construct(
        private GitHubClient $github,
        private array $config,
    ) {
    }

    public function generate(string $username): array
    {
        // Phase 1 — issue every independent request first (HttpClient runs them concurrently).
        $profileResponse = $this->github->requestProfile($username);
        $reposResponse = $this->github->requestReposPage($username, 1);
        $feedResponses = [];

        foreach ($this->config['blog']['feeds'] as $lang => $feed) {
            $feedResponses[$lang] = $this->github->requestFeed(
                rtrim($this->config['blog']['base_url'], '/').'/'.ltrim($feed, '/')
            );
        }

        // Phase 2 — consume. On failure, cancel every in-flight response first:
        // an unconsumed 4xx/5xx AsyncResponse would fatal in __destruct and mask our error.
        $pending = [$profileResponse, $reposResponse, ...array_values($feedResponses)];

        try {
            $profile = $this->github->decode($profileResponse, 'fetch profile');
            $repos = $this->collectRepos($reposResponse);

            $feeds = [];
            foreach ($feedResponses as $lang => $response) {
                $feeds[$lang] = $this->github->decode($response, sprintf('fetch %s blog feed', $lang));
            }

            $activeRepos = $this->slimRepos($repos);
            [$languages, $languageCount] = $this->collectLanguages($activeRepos);
            $blogArticles = $this->parseArticles($feeds);
        } catch (\Throwable $e) {
            foreach ($pending as $response) {
                $response->cancel();
            }

            throw $e;
        }

        $byStars = $activeRepos;
        usort($byStars, fn (array $a, array $b) => $b['stargazers_count'] <=> $a['stargazers_count']);

        return [
            'profile' => $profile,
            'repository_count' => count($repos),
            'language_count' => $languageCount,
            'total_stars' => array_sum(array_column($activeRepos, 'stargazers_count')),
            'total_forks' => array_sum(array_column($activeRepos, 'forks_count')),
            'top_repos' => array_slice($byStars, 0, $this->config['github']['top_repos_count']),
            'recent_repos' => array_slice($activeRepos, 0, $this->config['github']['recent_repos_count']),
            'languages' => $languages,
            'socials' => $this->config['socials'],
            'blog_articles' => $blogArticles,
        ];
    }

    private function collectRepos(ResponseInterface $firstPage): array
    {
        $pages = [];
        $response = $firstPage;
        $page = 1;

        do {
            $pages[] = $this->github->decode($response, sprintf('fetch repositories (page %d)', $page));
            $next = $this->github->nextPageUrl($response);
            $response = null !== $next ? $this->github->requestUrl($next) : null;
            ++$page;
        } while (null !== $response);

        return [] === $pages ? [] : array_merge(...$pages);
    }

    private function slimRepos(array $repos): array
    {
        $slim = [];

        foreach ($repos as $repo) {
            if (($repo['fork'] ?? false) || ($repo['archived'] ?? false)) {
                continue;
            }

            $slim[] = [
                'name' => (string) ($repo['name'] ?? ''),
                'full_name' => (string) ($repo['full_name'] ?? ''),
                'html_url' => (string) ($repo['html_url'] ?? ''),
                'description' => $repo['description'] ?? null,
                'pushed_at' => $repo['pushed_at'] ?? null,
                'stargazers_count' => (int) ($repo['stargazers_count'] ?? 0),
                'forks_count' => (int) ($repo['forks_count'] ?? 0),
            ];
        }

        return $slim;
    }

    private function collectLanguages(array $repos): array
    {
        // Fan-out: issue every request first, consume after.
        $responses = [];

        foreach ($repos as $repo) {
            if (!str_contains($repo['full_name'], '/')) {
                continue;
            }

            $responses[$repo['full_name']] = $this->github->requestRepoLanguages($repo['full_name']);
        }

        $totals = [];

        try {
            foreach ($responses as $fullName => $response) {
                foreach ($this->github->decode($response, sprintf('fetch languages of "%s"', $fullName)) as $name => $bytes) {
                    $totals[$name] = ($totals[$name] ?? 0) + $bytes;
                }
            }
        } catch (\Throwable $e) {
            foreach ($responses as $response) {
                $response->cancel();
            }

            throw $e;
        }

        arsort($totals);
        $totalBytes = array_sum($totals);

        $result = [];

        foreach (array_slice($totals, 0, 10, true) as $name => $bytes) {
            $result[] = [
                'name' => $name,
                'bytes' => $bytes,
                'percentage' => 0 !== $totalBytes ? round(($bytes / $totalBytes) * 100, 1) : 0.0,
                'color' => $this->config['languages'][$name] ?? '555555',
            ];
        }

        return [$result, count($totals)];
    }

    private function parseArticles(array $feeds): array
    {
        $articles = [];
        $max = $this->config['blog']['max_articles'];

        foreach ($feeds as $lang => $feedData) {
            $blogItems = array_filter(
                $feedData['data'] ?? [],
                fn (array $item) => ($item['type'] ?? '') === 'blog' && ($item['attributes']['published'] ?? false)
            );

            $dated = [];

            foreach ($blogItems as $item) {
                $attrs = $item['attributes'] ?? [];
                $title = $attrs['title'] ?? null;
                $url = $item['url'] ?? null;
                $rawDate = $attrs['date'] ?? null;

                if (!is_string($title) || '' === $title
                    || !is_string($url) || '' === $url
                    || !is_string($rawDate) || '' === $rawDate) {
                    continue;
                }

                try {
                    $date = new \DateTimeImmutable($rawDate);
                } catch (\DateException) {
                    continue;
                }

                $dated[] = [
                    'title' => $title,
                    'url' => $url,
                    'date' => $date,
                    'tags' => $attrs['tags'] ?? [],
                    'excerpt' => $attrs['excerpt'] ?? '',
                ];
            }

            usort($dated, fn (array $a, array $b) => $b['date'] <=> $a['date']);

            $articles[$lang] = array_slice($dated, 0, $max);
        }

        return $articles;
    }
}
