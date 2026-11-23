<?php

declare(strict_types=1);

use Robo\Tasks;

/**
 * Build script for the "Highlighting on Theme Edit" modification package.
 *
 * `composer build` downloads the current CodeMirror 5 assets into the theme
 * folders and packs the modification into a ZIP archive in the project root.
 */
class RoboFile extends Tasks
{
    private const SOURCE_DIR = 'src';

    private const STYLE_DIR = self::SOURCE_DIR . '/Themes/default/css/codemirror';

    private const SCRIPT_DIR = self::SOURCE_DIR . '/Themes/default/scripts/codemirror';

    private const NPM_REGISTRY = 'https://registry.npmjs.org/codemirror';

    private const FILE_LIST_API = 'https://data.jsdelivr.com/v1/package/npm/codemirror@%s/flat';

    private const CDN = 'https://cdn.jsdelivr.net/npm/codemirror@%s/%s';

    /**
     * Files from the project root added to the archive root.
     */
    private const ROOT_FILES = [
        'LICENSE',
    ];

    /**
     * CodeMirror 5 files required by Class-HighlightThemeEdit.php (remote path => local path).
     */
    private const CODE_MIRROR_FILES = [
        'lib/codemirror.js'             => self::SCRIPT_DIR . '/codemirror.js',
        'mode/xml/xml.js'               => self::SCRIPT_DIR . '/xml.js',
        'mode/javascript/javascript.js' => self::SCRIPT_DIR . '/javascript.js',
        'mode/css/css.js'               => self::SCRIPT_DIR . '/css.js',
        'mode/clike/clike.js'           => self::SCRIPT_DIR . '/clike.js',
        'mode/php/php.js'               => self::SCRIPT_DIR . '/php.js',
        'lib/codemirror.css'            => self::STYLE_DIR . '/codemirror.css',
    ];

    /**
     * Build the installable package archive.
     */
    public function build(): void
    {
        $version = $this->packageVersion();
        $archive = sprintf('highlighting_on_theme_edit_%s_smf21.zip', $version);

        $this->say("Building <info>$archive</info>");

        $this->taskFilesystemStack()
            ->remove($archive)
            ->run();

        $this->downloadCodeMirror();
        $this->pack($archive);

        $this->say("Archive created: <info>$archive</info>");
    }

    /**
     * Read the modification version from the package manifest.
     */
    private function packageVersion(): string
    {
        $path = self::SOURCE_DIR . '/package-info.xml';

        if (! is_file($path)) {
            throw new RuntimeException('src/package-info.xml not found');
        }

        $package = simplexml_load_file($path);

        if ($package === false) {
            throw new RuntimeException('Unable to parse src/package-info.xml');
        }

        $version = trim((string) $package->version);

        if ($version === '') {
            throw new RuntimeException('src/package-info.xml has no <version> element');
        }

        return $version;
    }

    /**
     * Download the newest CodeMirror 5 files into the empty theme folders.
     */
    private function downloadCodeMirror(): void
    {
        $version = $this->latestCodeMirror5Version();

        $this->say("Downloading CodeMirror <info>$version</info> assets");

        $this->removeGenerated(self::SCRIPT_DIR, '*.js');
        $this->removeGenerated(self::STYLE_DIR, '*.css');

        $files = self::CODE_MIRROR_FILES;

        foreach ($this->codeMirrorThemes($version) as $theme) {
            $files['theme/' . $theme] = self::STYLE_DIR . '/' . $theme;
        }

        foreach ($files as $remote => $local) {
            $this->download(sprintf(self::CDN, $version, $remote), $local);
        }

        $this->say(sprintf('Downloaded %d files', count($files)));
    }

    /**
     * Resolve the latest 5.x release of CodeMirror from the npm registry.
     */
    private function latestCodeMirror5Version(): string
    {
        $package = json_decode($this->fetch(self::NPM_REGISTRY), true);

        if (! is_array($package)) {
            throw new RuntimeException('Unable to read the CodeMirror package metadata');
        }

        $version = $package['dist-tags']['version5'] ?? null;

        if (is_string($version) && $version !== '') {
            return $version;
        }

        $versions = array_keys($package['versions'] ?? []);
        $versions = array_filter($versions, static fn (string $version): bool => str_starts_with($version, '5.'));

        if ($versions === []) {
            throw new RuntimeException('No CodeMirror 5 release found in the npm registry');
        }

        usort($versions, 'version_compare');

        return (string) end($versions);
    }

    /**
     * Get the list of theme stylesheets shipped with the CodeMirror 5 package.
     *
     * @return list<string>
     */
    private function codeMirrorThemes(string $version): array
    {
        $listing = json_decode($this->fetch(sprintf(self::FILE_LIST_API, $version)), true);

        if (! is_array($listing) || empty($listing['files'])) {
            throw new RuntimeException("Unable to list the files of CodeMirror $version");
        }

        $themes = [];

        foreach ($listing['files'] as $file) {
            $name = $file['name'] ?? '';

            if (str_starts_with($name, '/theme/') && str_ends_with($name, '.css')) {
                $themes[] = basename($name);
            }
        }

        sort($themes);

        if ($themes === []) {
            throw new RuntimeException("CodeMirror $version ships no theme stylesheets");
        }

        return $themes;
    }

    /**
     * Remove previously downloaded assets from a directory.
     */
    private function removeGenerated(string $directory, string $pattern): void
    {
        foreach (glob($directory . '/' . $pattern) ?: [] as $file) {
            unlink($file);
        }
    }

    /**
     * Download one file and store it under the given path.
     */
    private function download(string $url, string $path): void
    {
        $body = $this->fetch($url);

        if ($body === '') {
            throw new RuntimeException("Empty response while downloading $url");
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (file_put_contents($path, $body) === false) {
            throw new RuntimeException("Unable to write $path");
        }
    }

    /**
     * Fetch a remote resource.
     */
    private function fetch(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 120,
                'follow_location' => 1,
                'ignore_errors'   => true,
                'header'          => "User-Agent: highlighting-on-theme-edit-build\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException(sprintf(
                'Unable to download %s: %s',
                $url,
                error_get_last()['message'] ?? 'unknown error'
            ));
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Unable to download %s: HTTP %d', $url, $status));
        }

        return $body;
    }

    /**
     * Pack the contents of the source directory into a ZIP archive.
     */
    private function pack(string $archive): void
    {
        $root = realpath(self::SOURCE_DIR);

        if ($root === false) {
            throw new RuntimeException('Source directory not found');
        }

        $zip = new ZipArchive();

        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create $archive");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $packed = 0;

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getFilename() === '.gitkeep') {
                continue;
            }

            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (! $zip->addFile($file->getPathname(), $path)) {
                $zip->close();

                throw new RuntimeException("Unable to add $path to the archive");
            }

            $packed++;
        }

        foreach (self::ROOT_FILES as $file) {
            if (! is_file($file)) {
                $zip->close();

                throw new RuntimeException("$file not found in the project root");
            }

            if (! $zip->addFile($file, $file)) {
                $zip->close();

                throw new RuntimeException("Unable to add $file to the archive");
            }

            $packed++;
        }

        $zip->close();

        $this->say(sprintf('Packed %d files', $packed));
    }
}
