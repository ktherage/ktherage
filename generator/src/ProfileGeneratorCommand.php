<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Twig\Environment;

#[AsCommand(name: 'generate', description: 'Generate README.md and README.fr.md from Twig templates')]
final class ProfileGeneratorCommand extends Command
{
    public function __construct(
        private readonly GitHubClient $github,
        private readonly Environment $twig,
        private readonly ConfigLoader $config,
        private readonly Filesystem $filesystem,
        private readonly bool $authenticated = false,
    ) {
        parent::__construct();
    }

    public function __invoke(
        #[Argument(name: 'username', description: 'GitHub username')]
        string $username,
        OutputInterface $output,
        #[Argument(name: 'output-dir', description: 'Output directory')]
        ?string $outputDir = null,
    ): int {
        if (1 !== preg_match('/^[a-z\d](?:[a-z\d-]{0,37}[a-z\d])?$/i', $username)) {
            $output->writeln('<error>Invalid GitHub username.</error>');

            return Command::INVALID;
        }

        try {
            $config = $this->config->load();
            $outputDir = $this->prepareOutputDir($outputDir ?? dirname($this->config->getProjectDir()));
        } catch (\InvalidArgumentException|IOExceptionInterface $e) {
            $output->writeln(sprintf('<error>%s</error>', OutputFormatter::escape($e->getMessage())));

            return Command::FAILURE;
        }

        if (!$this->authenticated) {
            $output->writeln('<comment>Running without GITHUB_TOKEN: API rate limit is 60 requests/hour.</comment>');
        }

        $output->writeln(sprintf('<info>Generating profile for %s...</info>', OutputFormatter::escape($username)));

        try {
            $output->writeln('  Fetching GitHub data and blog articles...');
            $data = (new ProfileGenerator($this->github, $config))->generate($username);
            $output->writeln(sprintf(
                '  Found %d repositories, %d languages, %d EN articles, %d FR articles',
                $data['repository_count'],
                $data['language_count'],
                count($data['blog_articles']['en']),
                count($data['blog_articles']['fr'])
            ));

            $output->writeln('  Rendering templates...');
            foreach ($config['templates'] as $lang => $tpl) {
                $rendered = $this->twig->render($tpl['template'], array_merge($data, ['lang' => $config[$lang]]));
                $target = Path::join($outputDir, $tpl['output']);
                $this->filesystem->dumpFile($target, $rendered);
                $output->writeln(sprintf('<info>%s generated at %s</info>', strtoupper($lang), OutputFormatter::escape($target)));
            }
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', OutputFormatter::escape($e->getMessage())));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function prepareOutputDir(string $outputDir): string
    {
        $outputDir = Path::canonicalize($outputDir);

        try {
            $this->filesystem->mkdir($outputDir);
        } catch (IOExceptionInterface $e) {
            throw new \InvalidArgumentException(sprintf('Cannot create output directory "%s".', $outputDir), 0, $e);
        }

        if (!is_dir($outputDir) || !is_writable($outputDir)) {
            throw new \InvalidArgumentException(sprintf('Output directory "%s" is not writable.', $outputDir));
        }

        return $outputDir;
    }
}
