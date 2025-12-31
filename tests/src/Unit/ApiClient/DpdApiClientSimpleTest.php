<?php

namespace Drupal\Tests\commerce_dpd\Unit;

use Drupal\commerce_dpd\ApiClient\DpdApiClient;
use Drupal\commerce_dpd\Service\DpdAuthTokenManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests très simples pour DpdApiClient.
 *
 * @group commerce_dpd
 */
class DpdApiClientSimpleTest extends UnitTestCase {
  protected $tokenManager;
  protected $dpdApiClient;
  
  /**
   *
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $this->tokenManager = $this->createMock(DpdAuthTokenManagerInterface::class);
    $this->dpdApiClient = new DpdApiClient($loggerFactory, $this->tokenManager);
  }
  
  // public function testGetDelisId(): void {
  // $this->tokenManager->getDelisId()->willReturn('test_delis_id');
  // $this->tokenManager->getPassword()->willReturn('test_password');
  // }
  
  /**
   *
   * @covers ::getDelisId
   */
  public function testGetDelisIdThrowsExceptionWhenNotConfigured(): void {
    // Arrange
    $this->tokenManager->method('getDelisId')->willReturn('');
    
    // Le type de d'erreur doit etre "RuntimeException::class"
    $this->expectException(\RuntimeException::class);
    // Le message doit etre "DPD credentials are not configured'"
    $this->expectExceptionMessage('DPD credentials are not configured');
    
    // Act
    $this->invokeMethod($this->dpdApiClient, 'getDelisId');
  }
  
  public function testInstantiation(): void {
    $client = $this->dpdApiClient;
    $this->assertInstanceOf(DpdApiClient::class, $client);
  }
  
  /**
   * Tests de normalisation ISO-8859-1.
   *
   * @group commerce_dpd
   */
  public function testNormalizeIso88591(): void {
    $client = $this->dpdApiClient;
    $input = [
      'name1' => 'Müller',
      'city' => 'München',
      'postalCode' => '80331',
      'email' => 'test@example.com'
    ];
    
    $output = $this->invokeMethod($client, 'normalizeIso88591', [
      $input
    ]);
    
    $this->assertNotSame($input['name1'], $output['name1']);
    $this->assertSame($input['postalCode'], $output['postalCode']);
    $this->assertSame($input['email'], $output['email']);
  }
  
  /**
   * Helper pour appeler une méthode protected.
   */
  protected function invokeMethod(object $object, string $method, array $args = []) {
    $ref = new \ReflectionClass($object);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($object, $args);
  }
}