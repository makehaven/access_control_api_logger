<?php

namespace Drupal\access_control_api_logger\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Provides cached access to the fallback export payload.
 */
class FallbackStoreCache {

  private const CACHE_ID = 'access_control_api_logger:fallback_store';

  /**
   * Underlying payload builder.
   */
  protected FallbackStoreBuilder $builder;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  /**
   * Constructs the cache wrapper.
   */
  public function __construct(
    FallbackStoreBuilder $builder,
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    TimeInterface $time,
  ) {
    $this->builder = $builder;
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('access_control_api_logger');
    $this->time = $time;
  }

  /**
   * Return the cached payload, rebuilding it if necessary.
   */
  public function getPayload(bool $force_refresh = FALSE): array {
    $config = $this->getConfig();
    if (!$this->isCachingEnabled($config)) {
      return $this->builder->build();
    }

    if (!$force_refresh) {
      $cached = $this->cache->get(self::CACHE_ID);
      if ($cached) {
        return $cached->data;
      }
    }

    $payload = $this->builder->build();
    $expire = $this->time->getRequestTime() + $this->getCacheLifetime($config);

    try {
      $this->cache->set(self::CACHE_ID, $payload, $expire, ['access_control_api_logger:fallback_store']);
    }
    catch (\Throwable $e) {
      $this->logger->error('Unable to persist fallback export cache: @message', ['@message' => $e->getMessage()]);
    }

    return $payload;
  }

  /**
   * Warm the cache immediately (used by cron or manual calls).
   */
  public function warmCache(): void {
    $config = $this->getConfig();
    if (!$this->isCachingEnabled($config)) {
      return;
    }
    $this->getPayload(TRUE);
  }

  /**
   * Remove the cached payload so it is rebuilt on the next request.
   */
  public function invalidate(): void {
    $this->cache->delete(self::CACHE_ID);
  }

  /**
   * Determine whether caching is turned on.
   */
  protected function isCachingEnabled(ImmutableConfig $config): bool {
    $value = $config->get('fallback_cache_enabled');
    return $value === NULL ? TRUE : (bool) $value;
  }

  /**
   * Calculate the cache lifetime (minimum 60 seconds).
   */
  protected function getCacheLifetime(ImmutableConfig $config): int {
    $configured = (int) ($config->get('fallback_cache_max_age') ?? 900);
    if ($configured < 60) {
      return 60;
    }
    return $configured;
  }

  /**
   * Convenience accessor for the immutable config.
   */
  protected function getConfig(): ImmutableConfig {
    return $this->configFactory->get('access_control_api_logger.settings');
  }

}
