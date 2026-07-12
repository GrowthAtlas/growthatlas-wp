<?php

namespace GrowthAtlas;

/**
 * Compares the installed plugin version to the latest GitHub release.
 */
class VersionChecker
{
    public const GITHUB_RELEASES_API = 'https://api.github.com/repos/GrowthAtlas/growthatlas-wp/releases/latest';

    public const GITHUB_TAGS_API = 'https://api.github.com/repos/GrowthAtlas/growthatlas-wp/tags?per_page=20';

    public const RELEASES_URL = 'https://github.com/GrowthAtlas/growthatlas-wp/releases';

    public const TRANSIENT_KEY = 'growthatlas_latest_version';

    /** Cache GitHub lookups for 12 hours. */
    public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /**
     * @return array{current: string, latest: ?string, update_available: bool, checked: bool, releases_url: string}
     */
    public static function status(): array
    {
        $current = defined('GROWTHATLAS_VERSION') ? (string) GROWTHATLAS_VERSION : '0.0.0';
        $latest = self::latest_version();

        return [
            'current' => $current,
            'latest' => $latest,
            'update_available' => $latest !== null && version_compare($latest, $current, '>'),
            'checked' => $latest !== null,
            'releases_url' => self::RELEASES_URL,
        ];
    }

    public static function latest_version(): ?string
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $latest = self::fetch_latest_from_github();
        if ($latest !== null) {
            set_transient(self::TRANSIENT_KEY, $latest, self::CACHE_TTL);
        }

        return $latest;
    }

    protected static function fetch_latest_from_github(): ?string
    {
        $from_release = self::fetch_latest_release_tag();
        if ($from_release !== null) {
            return $from_release;
        }

        return self::fetch_latest_tag();
    }

    protected static function request_github(string $url): ?array
    {
        $response = wp_remote_get($url, [
            'timeout' => 5,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'GrowthAtlas-WordPress-Connector/' . (defined('GROWTHATLAS_VERSION') ? GROWTHATLAS_VERSION : '1.0.0'),
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($body) ? $body : null;
    }

    protected static function fetch_latest_release_tag(): ?string
    {
        $body = self::request_github(self::GITHUB_RELEASES_API);
        if ($body === null || ! empty($body['prerelease'])) {
            return null;
        }

        $tag = (string) ($body['tag_name'] ?? '');

        return $tag !== '' ? ltrim($tag, 'v') : null;
    }

    protected static function fetch_latest_tag(): ?string
    {
        $body = self::request_github(self::GITHUB_TAGS_API);
        if ($body === null || $body === []) {
            return null;
        }

        $versions = [];
        foreach ($body as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = ltrim((string) ($row['name'] ?? ''), 'v');
            if ($name === '' || preg_match('/(?:alpha|beta|rc)/i', $name)) {
                continue;
            }
            $versions[] = $name;
        }

        if ($versions === []) {
            return null;
        }

        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

        return $versions[0];
    }
}
