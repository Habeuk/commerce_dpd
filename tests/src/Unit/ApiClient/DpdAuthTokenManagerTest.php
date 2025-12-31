<?php

namespace Drupal\Tests\commerce_dpd\Unit\Service;

use Drupal\commerce_dpd\Service\DpdAuthTokenManager;
use Drupal\Tests\UnitTestCase;

/**
 *
 * @group commerce_dpd
 */
class DpdAuthTokenManagerTest extends UnitTestCase {
  
  public function testCacheTokenData() {
    // 1. Mock des dépendances
    $configFactory = $this->getConfigFactoryStub([
      'commerce_dpd.settings' => [
        'mode' => 'sandbox',
        'sandbox_delis_id' => 'test123',
        'sandbox_password' => 'pass123'
      ]
    ]);
    
    $keyValueStore = $this->createMock('Drupal\Core\KeyValueStore\KeyValueStoreInterface');
    
    $time = $this->createMock('Drupal\Component\Datetime\TimeInterface');
    $time->method('getCurrentTime')->willReturn(1234567890);
    
    $loggerFactory = $this->getLoggerFactoryStub();
    
    // 2. Créer le manager
    $manager = new DpdAuthTokenManager($configFactory, $this->createMock('Drupal\Core\KeyValueStore\KeyValueFactoryInterface'), $loggerFactory, $time);
    
    // 3. Injecter le mock keyValueStore via réflexion
    $reflection = new \ReflectionClass($manager);
    $property = $reflection->getProperty('store');
    $property->setAccessible(true);
    $property->setValue($manager, $keyValueStore);
    
    // 4. Tester
    $keyValueStore->expects($this->once())->method('set')->with('dpd_auth', [
      'authToken' => 'test_token',
      'created' => 1234567890
    ]);
    
    $manager->cacheTokenData([
      'authToken' => 'test_token'
    ]);
  }
}