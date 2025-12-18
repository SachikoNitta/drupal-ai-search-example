<?php

namespace Drupal\simple_ai_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\simple_ai_search\Service\SearchApiQueryService;
use Drupal\simple_ai_search\Service\GeminiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Simple search form.
 */
class SearchForm extends FormBase {

  /**
   * Default Search API index ID to use for searches.
   * 
   * Site administrators can change this to match their Search API index name.
   * Common values: 'content', 'default_solr_index', 'site_search'
   */
  public const SEARCH_INDEX_ID = 'index';

  /**
   * The search API query service.
   *
   * @var \Drupal\simple_ai_search\Service\SearchApiQueryService
   */
  protected $searchApiQueryService;

  /**
   * The Gemini service.
   *
   * @var \Drupal\simple_ai_search\Service\GeminiService
   */
  protected $geminiService;

  /**
   * Constructs a new SearchForm object.
   *
   * @param \Drupal\simple_ai_search\Service\SearchApiQueryService $search_api_query_service
   *   The search API query service.
   * @param \Drupal\simple_ai_search\Service\GeminiService $gemini_service
   *   The Gemini service.
   */
  public function __construct(SearchApiQueryService $search_api_query_service, GeminiService $gemini_service) {
    $this->searchApiQueryService = $search_api_query_service;
    $this->geminiService = $gemini_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('simple_ai_search.search_api_query'),
      $container->get('simple_ai_search.gemini')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simple_ai_search_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Keyword search field
    $form['search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search'),
      '#placeholder' => $this->t('Enter your search query...'),
      '#size' => 60,
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('search', ''),
    ];

    // Submit button
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
    ];

    // Show AI summary if available
    $ai_summary = $form_state->getTemporaryValue('ai_summary');
    if ($ai_summary) {
      $form['ai_summary'] = $this->renderAiSummary($ai_summary);
    }

    // Show results if available
    $results = $form_state->getTemporaryValue('search_results');
    if ($results) {
      $form['results'] = $this->renderResults($results);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $query = $form_state->getValue('search');

    try {
      // Call the search service directly
      $results = $this->searchApiQueryService->vectorSearch(self::SEARCH_INDEX_ID, $query, 10);

      $form_state->setTemporaryValue('search_results', [
        'query' => $query,
        'count' => count($results),
        'results' => $results,
      ]);

      // Generate AI summary
      $ai_summary = $this->generateAiSummary($query, $results);
      $form_state->setTemporaryValue('ai_summary', [
        'query' => $query,
        'summary' => $ai_summary,
      ]);

      if (empty($results)) {
        $this->messenger()->addMessage($this->t('No results found for "@query".', ['@query' => $query]));
      }

    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error performing search: @error', ['@error' => $e->getMessage()]));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * Render AI summary.
   */
  protected function renderAiSummary(array $summary_data) {
    return [
      '#theme' => 'ai_summary',
      '#summary' => $summary_data['summary'],
      '#attached' => [
        'library' => ['simple_ai_search/ai-summary'];
      ],
    ];
  }

  /**
   * Generate AI summary from search results using Gemini.
   */
  protected function generateAiSummary($query, array $results) {
    if (empty($results)) {
      return "I couldn't find any information about \"$query\". Try searching with different keywords or check the spelling.";
    }

    // Prepare content for Gemini
    $content_for_ai = "User Question: $query\n\nSearch Results:\n";

    foreach (array_slice($results, 0, 5) as $i => $result) {
      $content_for_ai .= "\nResult " . ($i + 1) . ":\n";
      $content_for_ai .= "Title: " . ($result['title'] ?? 'No title') . "\n";

      if (!empty($result['summary'])) {
        $content_for_ai .= "Content: " . $result['summary'] . "\n";
      }

      if (!empty($result['score'])) {
        $content_for_ai .= "Relevance Score: " . $result['score'] . "\n";
      }
    }

    // Create system prompt for company portal context
    $system_prompt = "You are a helpful AI assistant for a company portal. Your role is to:
- Provide accurate, professional, and helpful responses
- Summarize company documentation and resources clearly
- Help employees find the information they need
- Maintain a friendly but professional tone
- Always base your answers on the provided search results
- If information is incomplete, clearly state what is available and what might be missing
- Keep responses concise and actionable";

    // Create Gemini prompt
    $prompt = $content_for_ai . "\n\nPlease provide a helpful summary that directly answers the user's question \"$query\" based on the search results above. Keep the response conversational, informative, and under 200 words.";

    try {
      $gemini_response = $this->geminiService->generate($prompt, $system_prompt);

      if ($gemini_response) {
        return $gemini_response;
      } else {
        // Fallback to basic summary if Gemini fails
        return $this->generateBasicSummary($query, $results);
      }
    } catch (\Exception $e) {
      // Log error and fallback
      \Drupal::logger('simple_ai_search')->error('Gemini API error: @error', ['@error' => $e->getMessage()]);
      return $this->generateBasicSummary($query, $results);
    }
  }

  /**
   * Generate basic summary as fallback.
   */
  protected function generateBasicSummary($query, array $results) {
    $result_count = count($results);
    $summary = "Based on your search for \"$query\", I found $result_count relevant document(s). ";

    $summaries = array_filter(array_column($results, 'summary'));
    if (!empty($summaries)) {
      $combined_content = implode(' ', array_slice($summaries, 0, 3));
      if (strlen($combined_content) > 300) {
        $combined_content = substr($combined_content, 0, 300) . '...';
      }
      $summary .= "Here's what I found: " . $combined_content;
    }

    return $summary;
  }

  /**
   * Render search results.
   */
  protected function renderResults(array $results) {
    if (empty($results['results'])) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['search-results']],
        'no_results' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('No results found for "@query".', ['@query' => $results['query']]),
        ],
        '#attached' => [
          'library' => ['simple_ai_search/search-results'],
        ],
      ];
    }

    $render_array = [
      '#type' => 'container',
      '#attributes' => ['class' => ['search-results']],
      '#attached' => [
        'library' => ['simple_ai_search/search-results'],
      ],
      'header' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Found @count result(s) for "@query"', [
          '@count' => $results['count'],
          '@query' => $results['query'],
        ]),
      ],
    ];

    foreach ($results['results'] as $key => $result) {
      $result_item = [
        '#type' => 'container',
        '#attributes' => ['class' => ['search-result']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $result['title'] ?? $this->t('Untitled'),
        ],
      ];

      if (!empty($result['summary'])) {
        $result_item['summary'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $result['summary'],
        ];
      }

      if (!empty($result['url'])) {
        // Handle both internal paths (like /node/123) and external URLs
        try {
          if (strpos($result['url'], 'http') === 0) {
            // External URL
            $url = \Drupal\Core\Url::fromUri($result['url']);
          } else {
            // Internal path
            $url = \Drupal\Core\Url::fromUserInput($result['url']);
          }
          
          $result_item['link'] = [
            '#type' => 'html_tag',
            '#tag' => 'p',
            'link' => [
              '#type' => 'link',
              '#title' => $this->t('View full content'),
              '#url' => $url,
            ],
          ];
        } catch (\Exception $e) {
          // If URL is invalid, just show as text
          $result_item['link'] = [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('URL: @url', ['@url' => $result['url']]),
          ];
        }
      }

      if (!empty($result['score'])) {
        $result_item['score'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          'small' => [
            '#type' => 'html_tag',
            '#tag' => 'small',
            '#value' => $this->t('Score: @score', ['@score' => number_format($result['score'], 2)]),
          ],
        ];
      }

      $render_array['result_' . $key] = $result_item;
    }

    return $render_array;
  }

}
