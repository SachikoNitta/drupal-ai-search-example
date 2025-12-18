<?php

namespace Drupal\simple_ai_search\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Simplified service for Search API vector search operations.
 */
class SearchApiQueryService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a SearchApiQueryService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('simple_ai_search');
  }

  /**
   * Search using vector search with a specific index and query string.
   *
   * @param string $index_id
   *   The Search API index ID.
   * @param string $query
   *   The search query (usually a sentence for vector search).
   * @param int $limit
   *   Maximum number of results to return.
   *
   * @return array
   *   Array of search results.
   */
  public function vectorSearch($index_id, $query, $limit = 10) {
    try {
      $this->logger->info('Vector search: index=@index, query=@query', [
        '@index' => $index_id,
        '@query' => $query
      ]);
      
      return $this->searchByIndex($index_id, $query, $limit);
      
    } catch (\Exception $e) {
      $this->logger->error('Vector search error: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Get all available Search API indexes.
   *
   * @return array
   *   Array of available search indexes.
   */
  public function getAvailableIndexes() {
    $index_storage = $this->entityTypeManager->getStorage('search_api_index');
    $indexes = $index_storage->loadMultiple();
    
    $available_indexes = [];
    foreach ($indexes as $index) {
      /** @var \Drupal\search_api\IndexInterface $index */
      if ($index->status()) {
        $available_indexes[$index->id()] = [
          'id' => $index->id(),
          'label' => $index->label(),
          'description' => $index->getDescription(),
          'server' => $index->getServerId(),
        ];
      }
    }
    
    return $available_indexes;
  }

  /**
   * Search content by specific Search API index.
   *
   * @param string $index_id
   *   The Search API index ID.
   * @param string $query
   *   The search query.
   * @param int $limit
   *   Maximum number of results.
   *
   * @return array
   *   Search results.
   */
  public function searchByIndex($index_id, $query, $limit = 10) {
    try {
      // Load the Search API index
      $index = $this->entityTypeManager
        ->getStorage('search_api_index')
        ->load($index_id);

      if (!$index) {
        $this->logger->error('Search API index not found: @index_id', ['@index_id' => $index_id]);
        return [];
      }

      // Create and execute search query
      $search_query = $index->query()
        ->keys($query)
        ->range(0, $limit);
      
      $results = $search_query->execute();
      
      // Format and return results
      return $this->formatSearchResults($results);

    } catch (\Exception $e) {
      $this->logger->error('Search by index error: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Format Search API results into a simple structure.
   *
   * @param \Drupal\search_api\Query\ResultSetInterface $results
   *   The Search API results.
   *
   * @return array
   *   Formatted search results.
   */
  protected function formatSearchResults($results) {
    $formatted_results = [];
    
    foreach ($results->getResultItems() as $result_item) {
      try {
        $entity = $result_item->getOriginalObject()->getValue();
        
        if (!$entity) {
          continue;
        }
        
        $result = [
          'score' => $result_item->getScore() ?? 1.0,
          'title' => $entity->label(),
          'url' => $entity->hasLinkTemplate('canonical') ? $entity->toUrl()->toString() : '',
          'content_type' => $entity->getEntityTypeId() === 'node' ? $entity->bundle() : $entity->getEntityTypeId(),
        ];
        
        // Add summary from body field
        if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
          $body_value = $entity->get('body')->first()->get('value')->getValue();
          $result['summary'] = $this->truncateText(strip_tags($body_value), 200);
        }
        
        // Add author if available
        if ($entity->hasField('uid') && $entity->get('uid')->entity) {
          $result['author'] = $entity->get('uid')->entity->getDisplayName();
        }
        
        $formatted_results[] = $result;
        
      } catch (\Exception $e) {
        $this->logger->warning('Error processing search result: @message', ['@message' => $e->getMessage()]);
      }
    }
    
    return $formatted_results;
  }

  /**
   * Truncate text to specified length.
   */
  private function truncateText($text, $length = 200) {
    if (strlen($text) <= $length) {
      return $text;
    }
    return substr($text, 0, strrpos(substr($text, 0, $length), ' ')) . '...';
  }

}