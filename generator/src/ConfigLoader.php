<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class ConfigLoader
{
    public function __construct(
        private string $projectDir,
    ) {
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function load(): array
    {
        try {
            $raw = Yaml::parseFile($this->projectDir.'/config.yaml');
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(sprintf('Invalid config.yaml: %s', $e->getMessage()), 0, $e);
        }

        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Invalid config.yaml: expected a mapping at the root.');
        }

        $known = ['languages', 'blog', 'github', 'socials', 'templates'];

        $config = (new OptionsResolver())
            ->setRequired($known)
            ->setAllowedTypes('languages', 'array')
            ->setAllowedTypes('blog', 'array')
            ->setAllowedTypes('github', 'array')
            ->setAllowedTypes('socials', 'array')
            ->setAllowedTypes('templates', 'array')
            ->resolve(array_intersect_key($raw, array_flip($known)));

        // Remaining top-level keys are locale sections (e.g. "en", "fr").
        $config += array_diff_key($raw, array_flip($known));

        $config['github'] = (new OptionsResolver())
            ->setRequired(['top_repos_count', 'recent_repos_count'])
            ->setAllowedTypes('top_repos_count', 'int')
            ->setAllowedTypes('recent_repos_count', 'int')
            ->setAllowedValues('top_repos_count', fn (int $v): bool => $v >= 0)
            ->setAllowedValues('recent_repos_count', fn (int $v): bool => $v >= 0)
            ->resolve($config['github']);

        $config['blog'] = (new OptionsResolver())
            ->setRequired(['base_url', 'feeds', 'max_articles'])
            ->setAllowedTypes('base_url', 'string')
            ->setAllowedTypes('feeds', 'array')
            ->setAllowedTypes('max_articles', 'int')
            ->setAllowedValues('max_articles', fn (int $v): bool => $v >= 0)
            ->resolve($config['blog']);

        foreach ($config['templates'] as $lang => $tpl) {
            if (!is_array($tpl)
                || !is_string($tpl['template'] ?? null) || '' === $tpl['template']
                || !is_string($tpl['output'] ?? null) || '' === $tpl['output']) {
                throw new \InvalidArgumentException(sprintf('Invalid config.yaml: "templates.%s" must define a template and an output file.', $lang));
            }

            if (!isset($config[$lang]) || !is_array($config[$lang])) {
                throw new \InvalidArgumentException(sprintf('Invalid config.yaml: missing "%s" locale section.', $lang));
            }
        }

        return $config;
    }
}
