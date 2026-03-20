<?php

declare (strict_types=1);
/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Symfony\Flex;

use Composer\Util\Http_Downloader;
use Composer\Util\Remote_Filesystem;
class Github_Api
{
    /**
     * @param HttpDownloader|RemoteFilesystem $downloader
     */
    public function __construct(private $downloader)
    {
    }
    /**
     * Attempts to find data about when the recipe was installed.
     *
     * Returns an array containing:
     *      commit: The git sha of the last commit of the recipe
     *      date: The date of the commit
     *      new_commits: An array of commit sha's in this recipe's directory+version since the commit
     *                   The key is the sha & the value is the date
     */
    public function find_recipe_commit_data_from_tree_ref(string $package, string $repo, string $branch, string $version, string $lock_ref): ?array
    {
        $repository_name = $this->get_repository_name($repo);
        if (!$repository_name) {
            return null;
        }
        $recipe_path = \sprintf('%s/%s', $package, $version);
        $commits_data = $this->request_git_hub_api(\sprintf('https://api.github.com/repos/%s/commits?path=%s&sha=%s', $repository_name, $recipe_path, $branch));
        $commit_shas = [];
        foreach ($commits_data as $commit_data) {
            $commit_shas[$commit_data['sha']] = $commit_data['commit']['committer']['date'];
            // go back the commits one-by-one
            $tree_url = $commit_data['commit']['tree']['url'] . '?recursive=true';
            // fetch the full tree, then look for the tree for the package path
            $tree_data = $this->request_git_hub_api($tree_url);
            foreach ($tree_data['tree'] as $tree_item) {
                if ($tree_item['path'] !== $recipe_path) {
                    continue;
                }
                if ($tree_item['sha'] === $lock_ref) {
                    // remove *this* commit from the new commits list
                    array_pop($commit_shas);
                    return [
                        // shorten for brevity
                        'commit' => substr((string) $commit_data['sha'], 0, 7),
                        'date' => $commit_data['commit']['committer']['date'],
                        'new_commits' => $commit_shas,
                    ];
                }
            }
        }
        return null;
    }
    public function get_versions_of_recipe(string $repo, string $branch, string $recipe_path): ?array
    {
        $repository_name = $this->get_repository_name($repo);
        if (!$repository_name) {
            return null;
        }
        $url = \sprintf('https://api.github.com/repos/%s/contents/%s?ref=%s', $repository_name, $recipe_path, $branch);
        $contents = $this->request_git_hub_api($url);
        $versions = [];
        foreach ($contents as $file_data) {
            if ('dir' !== $file_data['type']) {
                continue;
            }
            $versions[] = $file_data['name'];
        }
        return $versions;
    }
    public function get_commit_data_for_path(string $repo, string $path, string $branch): array
    {
        $repository_name = $this->get_repository_name($repo);
        if (!$repository_name) {
            return [];
        }
        $commits_data = $this->request_git_hub_api(\sprintf('https://api.github.com/repos/%s/commits?path=%s&sha=%s', $repository_name, $path, $branch));
        $data = [];
        foreach ($commits_data as $commit_data) {
            $data[$commit_data['sha']] = $commit_data['commit']['committer']['date'];
        }
        return $data;
    }
    public function get_pull_request_for_commit(string $commit, string $repo): ?array
    {
        $data = $this->request_git_hub_api('https://api.github.com/search/issues?q=' . $commit . '+is:pull-request');
        if (0 === \count($data['items'])) {
            return null;
        }
        $repository_name = $this->get_repository_name($repo);
        if (!$repository_name) {
            return null;
        }
        $best_item = null;
        foreach ($data['items'] as $item) {
            // make sure the PR referenced isn't from a different repository
            if (!str_contains((string) $item['html_url'], \sprintf('%s/pull', $repository_name))) {
                continue;
            }
            if (null === $best_item) {
                $best_item = $item;
                continue;
            }
            // find the first PR to reference - avoids rare cases where an invalid
            // PR that references *many* commits is first
            // e.g. https://api.github.com/search/issues?q=a1a70353f64f405cfbacfc4ce860af623442d6e5
            if ($item['number'] < $best_item['number']) {
                $best_item = $item;
            }
        }
        if (!$best_item) {
            return null;
        }
        return ['number' => $best_item['number'], 'url' => $best_item['html_url'], 'title' => $best_item['title']];
    }
    private function request_git_hub_api(string $path): mixed
    {
        $contents = $this->downloader->get($path)->get_body();
        return json_decode($contents, true);
    }
    /**
     * Converts the "repo" stored in symfony.lock to a repository name.
     *
     * For example: "github.com/symfony/recipes" => "symfony/recipes"
     */
    private function get_repository_name(string $repo): ?string
    {
        // only supports public repository placement
        if (!str_starts_with($repo, 'github.com')) {
            return null;
        }
        $parts = explode('/', $repo);
        if (3 !== \count($parts)) {
            return null;
        }
        return implode('/', [$parts[1], $parts[2]]);
    }
}