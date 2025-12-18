<?php

namespace Drupal\simple_ai_search\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for calling Gemini API using env variable.
 */
class GeminiService {

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * @var string
   */
  protected $apiKey;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('gemini');

    // Retrieve API key from environment variable.
    $this->apiKey = getenv('GEMINI_API_KEY');

    if (!$this->apiKey) {
      $this->logger->error('GEMINI_API_KEY is not set in environment variables.');
    }
  }

  /**
   * Sends a prompt to Gemini API and returns the generated text.
   */
  public function generate(string $prompt, string $system_prompt = null): ?string {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent";

    // Build the contents array
    $contents = [];

    // Add system prompt if provided
    if ($system_prompt) {
      $contents[] = [
        'role' => 'user',
        'parts' => [
          ['text' => $system_prompt],
        ],
      ];
      $contents[] = [
        'role' => 'model',
        'parts' => [
          ['text' => 'I understand. I will act as a helpful assistant for the company portal, providing accurate and professional responses based on the available information.'],
        ],
      ];
    }

    // Add the main prompt
    $contents[] = [
      'role' => 'user',
      'parts' => [
        ['text' => $prompt],
      ],
    ];

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Content-Type' => 'application/json',
          'x-goog-api-key' => $this->apiKey,
        ],
        'json' => [
          'contents' => $contents,
        ],
      ]);

      $data = json_decode($response->getBody(), true);
      return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

    } catch (\Exception $e) {
      $this->logger->error('Gemini API request failed: @msg', ['msg' => $e->getMessage()]);
      return null;
    }
  }

}
