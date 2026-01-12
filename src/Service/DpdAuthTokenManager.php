<?php

namespace Drupal\commerce_dpd\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Manages DPD authentication tokens (LoginService V2.0).
 *
 * Conforms to LoginService-Public_2_0 specification.
 */
final class DpdAuthTokenManager implements DpdAuthTokenManagerInterface {
  private const STORE_KEY = 'dpd_auth';
  private const TOKEN_MAX_AGE = 86340; // 23h59 en secondes
  private const TOKEN_GENERATION_COUNT_KEY = 'dpd_token_generation_count';
  private const TOKEN_LAST_GENERATION_DATE_KEY = 'dpd_token_last_generation_date';
  public const MAX_TOKENS_PER_DAY = 5; // La valeaur max reelle est 10.
  /**
   * La documentation recommande une
   * generation à 03h01.
   *
   * @var integer
   */
  private const RESET_HOUR = 3;
  private const RESET_MINUTE = 1;
  protected $config;
  protected $store;
  
  public function __construct(ConfigFactoryInterface $config_factory, KeyValueFactoryInterface $key_value_factory, private readonly LoggerChannel $logger, private TimeInterface $time) {
    $this->config = $config_factory->get('commerce_dpd.settings');
    $this->store = $key_value_factory->get('commerce_dpd');
  }
  
  /**
   * Returns a valid cached authentication token.
   */
  public function getCachedTokenData(): ?array {
    $data = $this->store->get(self::STORE_KEY);
    if (!$data || !isset($data['authToken'])) {
      return null;
    }
    // Vérifier l'expiration
    if ($this->isTokenExpired($data)) {
      $this->clear();
      return null;
    }
    return $data;
  }
  
  /**
   * Sauvegarde les parametres et declenche une erreur au cas ou la sauvegarde
   * echoue.
   *
   * @param array $values
   */
  public function cacheTokenData(array $tokenData): void {
    if (empty($tokenData['authToken'])) {
      throw new \InvalidArgumentException('Token data must contain authToken');
    }
    $tokenData['created'] = $this->time->getCurrentTime();
    $this->store->set(self::STORE_KEY, $tokenData);
  }
  
  /**
   * Clears cached authentication data.
   */
  public function clear(): void {
    $this->store->delete(self::STORE_KEY);
    $this->logger->debug('DPD auth token cache cleared.');
  }
  
  /**
   * Vérifie si un token valide est en cache.
   */
  public function hasValidToken(): bool {
    return $this->getCachedTokenData() !== null;
  }
  
  /**
   * Récupère l'âge du token en cache (en secondes).
   */
  public function getTokenAge(): ?int {
    $data = $this->getCachedTokenData();
    if (!$data || !isset($data['created'])) {
      return null;
    }
    return $this->time->getCurrentTime() - $data['created'];
  }
  
  /**
   * Incrémente le compteur de génération de tokens avec reset quotidien à 3h01.
   *
   * @throws \RuntimeException Si la limite de 10 tokens/jour est atteinte
   */
  public function incrementTokenGenerationCount(): void {
    $currentTime = $this->time->getCurrentTime();
    $lastGenerationDate = $this->store->get(self::TOKEN_LAST_GENERATION_DATE_KEY, 0);
    
    // Vérifier si on est après 3h01 du matin
    $shouldReset = $this->shouldResetCounter($currentTime, $lastGenerationDate);
    
    if ($shouldReset) {
      // Réinitialiser le compteur (nouveau jour après 3h01)
      $this->store->set(self::TOKEN_GENERATION_COUNT_KEY, 1);
      $this->store->set(self::TOKEN_LAST_GENERATION_DATE_KEY, $currentTime);
      $this->logger->debug('DPD token counter reset (new day after 3:01 AM).');
      return;
    }
    
    // Incrémenter le compteur existant
    $count = $this->store->get(self::TOKEN_GENERATION_COUNT_KEY, 0);
    $count++;
    
    // Vérifier la limite
    if ($count > self::MAX_TOKENS_PER_DAY) {
      $this->logger->error('DPD token generation limit reached: @count/10 tokens today.', [
        '@count' => $count
      ]);
      throw new \RuntimeException(sprintf('Maximum DPD token generation limit reached (%d/%d tokens today). ' . 'Please wait until tomorrow after 3:01 AM.', $count - 1, self::MAX_TOKENS_PER_DAY));
    }
    
    // Mettre à jour le compteur
    $this->store->set(self::TOKEN_GENERATION_COUNT_KEY, $count);
    
    // Mettre à jour la date seulement si c'est le premier token de la période
    if ($count === 1) {
      $this->store->set(self::TOKEN_LAST_GENERATION_DATE_KEY, $currentTime);
    }
    
    $this->logger->debug('DPD token generation count: @count/' . self::MAX_TOKENS_PER_DAY, [
      '@count' => $count
    ]);
  }
  
  /**
   * Récupère le nombre de tokens générés aujourd'hui (depuis 3h01).
   */
  public function getTokenGenerationCountToday(): int {
    $currentTime = $this->time->getCurrentTime();
    $lastGenerationDate = $this->store->get(self::TOKEN_LAST_GENERATION_DATE_KEY, 0);
    
    // Vérifier si le compteur devrait être réinitialisé.
    if ($this->shouldResetCounter($currentTime, $lastGenerationDate)) {
      return 0;
    }
    
    return $this->store->get(self::TOKEN_GENERATION_COUNT_KEY, 0);
  }
  
  /**
   * Détermine si le compteur doit être réinitialisé (après 3h01 du matin).
   *
   * @param int $currentTime
   *        Timestamp actuel
   * @param int $lastGenerationDate
   *        Timestamp de la dernière génération
   * @return bool
   */
  private function shouldResetCounter(int $currentTime, int $lastGenerationDate): bool {
    if ($lastGenerationDate === 0) {
      return true; // Premier token jamais généré
    }
    
    // Convertir en dates/heures pour la comparaison
    $currentDateTime = new \DateTime();
    $currentDateTime->setTimestamp($currentTime);
    $currentDateTime->setTimezone(new \DateTimeZone('Europe/Berlin'));
    
    $lastDateTime = new \DateTime();
    $lastDateTime->setTimestamp($lastGenerationDate);
    $lastDateTime->setTimezone(new \DateTimeZone('Europe/Berlin'));
    
    // Vérifier si on a passé 3h01 du matin
    $resetTimeToday = clone $currentDateTime;
    $resetTimeToday->setTime(self::RESET_HOUR, self::RESET_MINUTE, 0);
    
    $resetTimeYesterday = clone $resetTimeToday;
    $resetTimeYesterday->modify('-1 day');
    
    // Si la dernière génération était avant 3h01 aujourd'hui
    // ET que maintenant on est après 3h01 aujourd'hui → RESET
    if ($lastDateTime < $resetTimeToday && $currentDateTime >= $resetTimeToday) {
      return true;
    }
    
    // Si la dernière génération était avant 3h01 hier
    // ET que maintenant on est après 3h01 hier mais avant 3h01 aujourd'hui
    // (cas rare mais possible)
    if ($lastDateTime < $resetTimeYesterday && $currentDateTime >= $resetTimeYesterday) {
      return true;
    }
    
    // Si plus de 24h se sont écoulées (sécurité)
    if (($currentTime - $lastGenerationDate) > 86400) {
      return true;
    }
    
    return false;
  }
  
  public function getDelisId(): string {
    $mode = $this->config->get('mode');
    $key = $mode === 'production' ? 'production_delis_id' : 'sandbox_delis_id';
    return $this->config->get($key);
  }
  
  public function getPassword(): string {
    $mode = $this->config->get('mode');
    $key = $mode === 'production' ? 'production_password' : 'sandbox_password';
    return $this->config->get($key);
  }
  
  public function getMode(): string {
    return $this->config->get('mode');
  }
  
  private function isTokenExpired(array $tokenData): bool {
    if (!isset($tokenData['created'])) {
      return true;
    }
    $age = $this->time->getCurrentTime() - $tokenData['created'];
    return $age >= self::TOKEN_MAX_AGE;
  }
}
