<?php

namespace Drupal\datastore;

use Drupal\common\Resource;
use Drupal\datastore\Service\DatastoreQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\common\JsonResponseTrait;
use Drupal\common\Util\RequestParamNormalizer;

/**
 * Class Api.
 *
 * @package Drupal\datastore
 *
 * @codeCoverageIgnore
 */
class WebServiceApi implements ContainerInjectionInterface {
  use JsonResponseTrait;

  /**
   * Datastore Service.
   *
   * @var \Drupal\datastore\Service
   */
  protected $datastoreService;

  /**
   * Request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * Api constructor.
   */
  public function __construct(Service $datastoreService, RequestStack $requestStack) {
    $this->datastoreService = $datastoreService;
    $this->requestStack = $requestStack;
  }

  /**
   * Create controller object from dependency injection container.
   */
  public static function create(ContainerInterface $container) {
    $datastoreService = $container->get('datastore.service');
    $requestStack = $container->get('request_stack');
    return new WebServiceApi($datastoreService, $requestStack);
  }

  /**
   * Returns the dataset along with datastore headers and statistics.
   *
   * @param string $identifier
   *   Identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function summary($identifier) {
    try {
      $data = $this->datastoreService->summary($identifier);
      return $this->getResponse($data);
    }
    catch (\Exception $e) {
      $exception = new \Exception("A datastore for resource {$identifier} does not exist.");
      return $this->getResponseFromException($exception, 404);
    }
  }

  /**
   * Import.
   */
  public function import() {

    $payloadJson = $this->requestStack->getCurrentRequest()->getContent();
    $payload = json_decode($payloadJson);

    if (isset($payload->resource_ids)) {
      return $this->importMultiple($payload->resource_ids);
    }

    if (!isset($payload->resource_id)) {
      return $this->getResponseFromException(new \Exception("Invalid payload."));
    }

    try {
      $resourceId = $payload->resource_id;
      $identifier = NULL; $version = NULL;
      list($identifier, $version) = Resource::getIdentifierAndVersion($resourceId);
      $results = $this->datastoreService->import($identifier, FALSE, $version);
      return $this->getResponse($results);
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e);
    }
  }

  /**
   * Private.
   */
  private function importMultiple(array $resourceIds) {

    $responses = [];
    foreach ($resourceIds as $identifier) {
      try {
        $results = $this->datastoreService->import($identifier, TRUE);
        $responses[$identifier] = $results;
      }
      catch (\Exception $e) {
        $responses[$identifier] = $e->getMessage();
      }
    }

    return $this->getResponse($responses);
  }

  /**
   * Drop.
   *
   * @param string $identifier
   *   The uuid of a resource.
   */
  public function delete($identifier) {
    try {
      $this->datastoreService->drop($identifier);
      return $this->getResponse(
        [
          "identifier" => $identifier,
          "message" => "The datastore for resource {$identifier} was successfully dropped.",
        ]
      );
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e);
    }
  }

  /**
   * Drop multiples.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Json response.
   */
  public function deleteMultiple() {
    $payloadJson = $this->requestStack->getCurrentRequest()->getContent();
    $payload = json_decode($payloadJson);

    if (!isset($payload->resource_ids)) {
      return $this->getResponseFromException(new \Exception("Invalid payload."));
    }

    $identifiers = $payload->resource_ids;

    $responses = [];
    foreach ($identifiers as $identifier) {
      $responses[$identifier] = json_decode($this->delete($identifier)->getContent());
    }

    return $this->getResponse($responses);
  }

  /**
   * Returns a list of import jobs and data about their status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function list() {
    try {
      $data = $this->datastoreService->list();
      return $this->getResponse($data);
    }
    catch (\Exception $e) {
      return $this->getResponseFromException(
        new \Exception("No importer data was returned. {$e->getMessage()}"),
        404
      );
    }
  }

  /**
   * Perform a query on one or more datastore resources.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function query() {
    $payloadJson = RequestParamNormalizer::getFixedJson(
      $this->requestStack->getCurrentRequest(),
      file_get_contents(__DIR__ . "/../docs/query.json")
    );

    try {
      $datastoreQuery = new DatastoreQuery($payloadJson);
      $result = $this->datastoreService->runQuery($datastoreQuery);
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e, 400);
    }

    return $this->getResponse($result->{"$"}, 200);
  }

  /**
   * Perform a query on a single datastore resource.
   *
   * @param string $identifier
   *   The uuid of a resource.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function queryResource($identifier) {
    $payloadJson = RequestParamNormalizer::getJson($this->requestStack->getCurrentRequest());
    try {
      $this->prepareQueryResourcePayload($payloadJson, $identifier);
    }
    catch (\Exception $e) {
      return $this->getResponseFromException(
        new \Exception("Invalid query JSON: {$e->getMessage()}"),
        400
      );
    }
    try {
      $payloadJson = RequestParamNormalizer::fixTypes($payloadJson, file_get_contents(__DIR__ . "/../docs/query.json"));
      $datastoreQuery = new DatastoreQuery($payloadJson);
      $result = $this->datastoreService->runQuery($datastoreQuery);
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e, 400);
    }

    return $this->getResponse($result->{"$"}, 200);
  }

  /**
   * Retrieve the datastore query schema. Used by datastore.1.query.schema.get.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function querySchema() {
    $schema = json_decode(file_get_contents(__DIR__ . "/../docs/query.json"), TRUE);
    return $this->getResponse($schema, 200);
  }

  /**
   * Normalize the simplified resource query to a standard datastore query.
   *
   * @param string $json
   *   A JSON payload.
   * @param mixed $identifier
   *   Resource identifier to query against.
   */
  private function prepareQueryResourcePayload(&$json, $identifier) {
    $data = json_decode($json);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception(json_last_error_msg());
    }
    if (!empty($data->resources) || !empty($data->joins)) {
      throw new \Exception("Joins are not available and "
        . "resources should not be explicitly passed when using the resource "
        . "query endpoint. Try /api/1/datastore/query.");
    }
    $resource = (object) ["id" => $identifier, "alias" => "t"];
    $data->resources = [$resource];
    $json = json_encode($data);
  }

}
