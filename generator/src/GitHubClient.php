<?php

declare(strict_types=1);

namespace App;

use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class GitHubClient
{
    public function __construct(
        private HttpClientInterface $client,
    ) {
    }

    /**
     * Issues a lazy request: nothing is sent until the response is consumed.
     * Issue every independent request first, then consume for concurrency.
     */
    public function requestProfile(string $username): ResponseInterface
    {
        return $this->client->request('GET', 'https://api.github.com/users/'.rawurlencode($username));
    }

    public function requestReposPage(string $username, int $page): ResponseInterface
    {
        return $this->client->request('GET', 'https://api.github.com/users/'.rawurlencode($username).'/repos', [
            'query' => ['per_page' => 100, 'sort' => 'pushed', 'page' => $page],
        ]);
    }

    public function requestUrl(string $url): ResponseInterface
    {
        return $this->client->request('GET', $url);
    }

    public function requestRepoLanguages(string $fullName): ResponseInterface
    {
        [$owner, $repo] = explode('/', $fullName, 2);

        return $this->client->request('GET', sprintf(
            'https://api.github.com/repos/%s/%s/languages',
            rawurlencode($owner),
            rawurlencode($repo)
        ));
    }

    public function requestFeed(string $url): ResponseInterface
    {
        return $this->client->request('GET', $url);
    }

    public function nextPageUrl(ResponseInterface $response): ?string
    {
        foreach ($response->getHeaders(false)['link'] ?? [] as $link) {
            if (1 === preg_match('/<([^>]+)>;\s*rel="next"/', $link, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Consumes a response: fails fast on HTTP errors, decodes JSON.
     *
     * @throws TransportExceptionInterface
     */
    public function decode(ResponseInterface $response, string $action): array
    {
        $status = $response->getStatusCode();

        if (200 !== $status) {
            throw new \RuntimeException($this->errorDetail($response, $action, $status));
        }

        try {
            return $response->toArray();
        } catch (DecodingExceptionInterface $e) {
            throw new \RuntimeException(sprintf('Failed to %s: invalid JSON response.', $action), 0, $e);
        }
    }

    private function errorDetail(ResponseInterface $response, string $action, int $status): string
    {
        $message = 'Unknown error';
        $decoded = json_decode($response->getContent(false), true);

        if (is_array($decoded) && is_string($decoded['message'] ?? null)) {
            $message = $decoded['message'];
        }

        $detail = sprintf('Failed to %s: GitHub API error %d: %s', $action, $status, $message);

        if (403 === $status || 429 === $status) {
            $headers = $response->getHeaders(false);
            $reset = $headers['x-ratelimit-reset'][0] ?? null;

            if (null !== $reset) {
                $detail .= sprintf(' (rate limit resets at %s)', date('c', (int) $reset));
            }
        }

        return $detail;
    }
}
