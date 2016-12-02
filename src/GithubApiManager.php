<?php

namespace Drupal\github_api;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Github\Api\GitData\References;
use Github\Client;
use Github\HttpClient\CachedHttpClient;

/**
 * Class GithubApiManager.
 *
 * @package Drupal\github_api
 */
class GithubApiManager {

  /**
   * Config factory object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   */
  protected $configFactory;

  /**
   * Logger factory object.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface;
   */
  protected $logger;

  /**
   * Config object.
   *
   * @var \stdClass;
   */
  protected $config;

  /**
   * Github client object.
   *
   * @var \Github\Client;
   */
  protected $client;

  protected $useAuthenticatedCalls;

  /**
   * Constructs a GithubApiManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_facotry) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_facotry;
    $this->useAuthenticatedCalls = TRUE;
    $this->config = $config_factory->get('github_api.settings');
  }

  /**
   * Sets the use authenticatied calls property.
   *
   * @param bool $auth
   *   TRUE to use authenticated calls, FALSE otherwise.
   */
  public function setUseAuthenticatedCalls($auth) {
    $this->useAuthenticatedCalls = $auth;
  }

  private function getClient() {
    $cache_obj = NULL;
    $use_cache = $this->config->get('github_api_use_cache');
    if ($use_cache === NULL) {
      $use_cache = TRUE;
    }

    if ($use_cache) {
      $cache_obj = new CachedHttpClient();
      $cache_obj->setCache(new DrupalGithubApiHttpClientCacheDrupalNativeCache());
    }

    $client = new Client($cache_obj);

    if ($this->useAuthenticatedCalls) {
      $token = $this->config->get('github_api_token');
      $user = $this->config->get('github_api_username');
      if ($token) {
        $client->authenticate($user, $token, Client::AUTH_HTTP_PASSWORD);
      }
      else {
        $client->authenticate($user, $this->config->get('github_api_password'), Client::AUTH_HTTP_TOKEN);
      }
    }

    return $client;
  }

  /**
   * Request a GitHub API oAuth token.
   *
   * @param string $username
   *   The GitHub username to use for the request.
   * @param string $password
   *   The password for the GitHub user.
   * @param string $note
   *   A note to annotate the token in GitHub.
   *
   * @return string
   *   The oAuth token.
   */
  public function githubApiGetToken($username, $password, $note = '') {
    $config = $this->configFactory->get('system.site');

    $client = new Client();
    $client->authenticate($username, $password, Client::AUTH_HTTP_PASSWORD);
    if (!$note) {
      $note = $config->get('name');
    }

    $params = array(
      'note' => $note,
      'note_url' => $GLOBALS['base_url'],
      'scopes' => array('user', 'repo'),
    );

    $response = $client->api('authorizations')->create($params);
    if (!empty($response['token'])) {
      return $response['token'];
    }
  }

  /**
   * Fetches a diff between 2 commits or branches.
   *
   * @param string $username
   *   The GitHub username of the repo owner.
   * @param string $repository
   *   The name of the repository.
   * @param string $base
   *   The name or hash to use as the base for the comparison.
   * @param string $head
   *   The name of the head.
   *
   * @return array
   *
   * @throws \Exception
   */
  public function githubApiDiff($username, $repository, $base, $head) {
    $client = $this->getClient();
    $response = $client->api('repo')->commits()->compare($username, $repository, $base, $head, 'application/vnd.github.v3.diff');
    return $response;
  }

  /**
   * Function to create a branch from a given head.
   *
   * @param string $username
   *   The GitHub username of the repo owner.
   * @param string $repository
   *   The name of the repository.
   * @param string $source
   *   The name of the head that the branch will be created from.
   * @param string $destination
   *   The name of the new branch head.
   *
   * @return array
   *
   * @throws \Exception
   */
  public function githubApiCreateBranch($username = NULL, $repository = NULL, $source = NULL, $destination = NULL) {
    $client = $this->getClient();

    try {
      $client->api('current_user')->show();

      $references = $client->api('git')->references();

      $branch_head = $references->show($username, $repository, 'heads/' . $source);

      if (!($references instanceof References)) {
        throw new \Exception('Create action failed.');
      }

      $params = array(
        'ref' => 'refs/heads/' . $destination,
        'sha' => $branch_head['object']['sha'],
      );

      return $references->create($username, $repository, $params);
    }
    catch (\Exception $exception) {
      $this->logger->get('github_api')->error('@exeption', array('@exception' => $exception));
    }
  }

  /**
   * Function to merge a head into a branch from a given repository.
   *
   * @param string $username
   *   The GitHub username of the repo owner.
   * @param string $repository
   *   The name of the repository.
   * @param string $source
   *   The head to merge. This can be a branch name or a commit SHA1.
   * @param string $destination
   *   The name of the base branch that the head will be merged into.
   * @param string $message
   *   Commit message to use for the merge commit. If omitted, a default message
   * will be used.
   *
   * @return array
   *
   * @throws \Exception
   */
  function githubApiMerge($username = NULL, $repository = NULL, $source = NULL, $destination = NULL, $message = NULL) {
    $client = $this->getClient();

    try {
      $client->api('current_user')->show();

      $repo_object = $client->api('repo');
      $references = $client->api('git')->references();

      // @TODO Not used, but in D7 version, remove?
      $branch_head = $references->show($username, $repository, 'heads/' . $source);

      if (!($references instanceof References) || !($repo_object instanceof Repo)) {
        throw new \Exception('Merge action failed.');
      }

      return $repo_object->merge($username, $repository, $destination, $source, $message);
    }
    catch (\Exception $exception) {
      $this->logger->get('github_api')->error('@exeption', array('@exception' => $exception));
    }
  }

  /**
   * Function to merge a head into a branch from a given repository.
   *
   * @param string $username
   *   The GitHub username of the repo owner.
   * @param string $repository
   *   The name of the repository.
   * @param string $source
   *   The base head to merge or create a new branch. This can be a branch name
   * or a commit SHA1.
   * @param string $destination
   *   The name of the new branch or the branch that will be merged into.
   * @param string $message
   *   Commit message to use for the merge commit. If omitted, a default message
   * will be used.
   *
   * @return array
   *
   * @throws \Exception
   */
  function githubApiCreateOrMergeBranch($username = NULL, $repository = NULL, $source = NULL, $destination = NULL, $message = NULL) {
    $client = $this->getClient();

    try {
      $client->api('current_user')->show();

      $references = $client->api('git')->references();

      if (!($references instanceof References)) {
        throw new \Exception('Create or merge action failed.');
      }

      // Throws not found exception
      $branch_source = $references->show($username, $repository, 'heads/' . $source);
      try {
        $branch_dest = $references->show($username, $repository, 'heads/' . $destination);
      }
      catch (\Exception $exception) {
        $params = array(
          'ref' => 'refs/heads/' . $destination,
          'sha' => $branch_source['object']['sha'],
        );

        return $references->create($username, $repository, $params);
      }
      // Finally
      $repo_object = $client->api('repo');
      if (!($repo_object instanceof Repo)) {
        throw new \Exception('Merge action failed.');
      }

      return $repo_object->merge($username, $repository, $destination, $source, $message);
    }
    catch (\Exception $exception) {
      $this->logger->get('github_api')->error('@exeption', array('@exception' => $exception));
    }
  }

  /**
   * Function to fetch a file from a git repository.
   *
   * @param string $username
   *   Username of the repo.
   * @param string $repository
   *   Respository name.
   * @param string $path
   *   Path of the file.
   * @param string $reference
   *   Reference location.
   *
   * @return array
   */
  function githubApiGetFile($username, $repository, $path, $reference) {
    $client = $this->getClient();

    try {
      return $client->api('repo')->contents()->show($username, $repository, $path, $reference);
    }
    catch (\Exception $exception) {
      $this->logger->get('github_api')->error('@exeption', array('@exception' => $exception));
    }
  }

  /**
   * Function to commit a file change to the repository.
   *
   * @param string $username
   *   Username of the repo.
   * @param string $repository
   *   Repository name.
   * @param string $path
   *   Path to the file.
   * @param string $content
   *   Contents of the file.
   * @param string $message
   *   The commit message.
   * @param string $sha
   *   Hash of the original file.
   * @param $branch
   *   Branch to make the commit to.
   * @param $committer
   *   Github user to commit the change as.
   *
   * @return array
   */
  function githubApiPushCommit($username, $repository, $path, $content, $message, $sha, $branch, $committer) {
    $client = $this->getClient();

    try{
      return $client->api('repo')->contents()->update($username, $repository, $path, $content, $message, $sha, $branch, $committer);
    }
    catch (\Exception $exception) {
      $this->logger->get('github_api')->error('@exeption', array('@exception' => $exception));
    }
  }

}
