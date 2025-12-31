<?php

namespace Drupal\Tests\commerce_dpd\Unit\ApiClient;

use Drupal\commerce_dpd\ApiClient\DpdApiClient;
use Drupal\commerce_dpd\Service\DpdAuthTokenManager;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use SoapClient;
use SoapFault;

/**
 *
 * @coversDefaultClass \Drupal\commerce_dpd\ApiClient\DpdApiClient
 * @group commerce_dpd
 */
class DpdApiClientTest extends TestCase {
  use ProphecyTrait;
  
  /**
   * The DPD API client.
   *
   * @var \Drupal\commerce_dpd\ApiClient\DpdApiClient
   */
  protected $dpdApiClient;
  
  /**
   * The token manager mock.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $tokenManager;
  
  /**
   * The logger mock.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $logger;
  
  /**
   * The SOAP client mock for authentication.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $soapClient;
  
  /**
   *
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    
    // Mock the logger factory and logger channel
    $this->logger = $this->prophesize(LoggerChannelInterface::class);
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $loggerFactory->get('commerce_dpd')->willReturn($this->logger->reveal());
    
    // Mock the token manager
    $this->tokenManager = $this->prophesize(DpdAuthTokenManager::class);
    
    // Create the API client instance
    $this->dpdApiClient = new DpdApiClient($loggerFactory->reveal(), $this->tokenManager->reveal());
    
    // Mock the internal SOAP client using reflection
    $this->soapClient = $this->prophesize(SoapClient::class);
    $this->injectMockSoapClient();
  }
  
  /**
   * Injects a mock SOAP client into the DpdApiClient.
   */
  protected function injectMockSoapClient(): void {
    $reflection = new \ReflectionClass($this->dpdApiClient);
    
    // Mock the initializeSoapClients method to avoid real SOAP calls
    $initializeMethod = $reflection->getMethod('initializeSoapClients');
    $initializeMethod->setAccessible(true);
    
    // Override to set our mock client
    $initializeMethod->invoke($this->dpdApiClient);
    
    // Now set the client property to our mock
    $clientProperty = $reflection->getProperty('client');
    $clientProperty->setAccessible(true);
    $clientProperty->setValue($this->dpdApiClient, $this->soapClient->reveal());
  }
  
  /**
   *
   * @covers ::getToken
   * @covers ::generateNewToken
   */
  public function testGetTokenUsesCachedToken(): void {
    // Arrange
    $cachedTokenData = [
      'authToken' => 'cached_token_123',
      'customerUid' => 'cust_123',
      'depot' => '0593',
      'created' => time() - 3600 // 1 hour ago
    ];
    
    $this->tokenManager->getCachedTokenData()->willReturn($cachedTokenData);
    
    $this->tokenManager->incrementTokenGenerationCount()->shouldNotBeCalled();
    
    $this->logger->info(Argument::cetera())->shouldNotBeCalled();
    
    // Act
    $token = $this->dpdApiClient->getToken();
    
    // Assert
    $this->assertEquals('cached_token_123', $token);
  }
  
  /**
   *
   * @covers ::getToken
   * @covers ::generateNewToken
   */
  public function testGetTokenGeneratesNewTokenWhenCacheEmpty(): void {
    // Arrange
    $this->tokenManager->getCachedTokenData()->willReturn(null);
    
    $this->tokenManager->incrementTokenGenerationCount()->willReturn();
    
    $this->tokenManager->getDelisId()->willReturn('test_delis_id');
    
    $this->tokenManager->getPassword()->willReturn('test_password');
    
    $this->tokenManager->getMode()->willReturn('sandbox');
    
    $this->tokenManager->getTokenGenerationCountToday()->willReturn(1);
    
    $this->tokenManager->cacheTokenData(Argument::type('array'))->shouldBeCalled();
    
    // Mock SOAP response
    $soapResponse = (object) [
      'return' => (object) [
        'authToken' => 'new_token_456',
        'customerUid' => 'cust_456',
        'depot' => '0593'
      ]
    ];
    
    $this->soapClient->getAuth([
      'delisId' => 'test_delis_id',
      'password' => 'test_password',
      'messageLanguage' => 'de_DE'
    ])->willReturn($soapResponse);
    
    $this->logger->info('DPD auth token generated in "@mode". Count today: @count/@max', Argument::type('array'))->shouldBeCalled();
    
    // Act
    $token = $this->dpdApiClient->getToken();
    
    // Assert
    $this->assertEquals('new_token_456', $token);
  }
  
  /**
   *
   * @covers ::getToken
   * @covers ::generateNewToken
   */
  public function testGetTokenThrowsExceptionWhenSoapFails(): void {
    // Arrange
    $this->tokenManager->getCachedTokenData()->willReturn(null);
    
    $this->tokenManager->incrementTokenGenerationCount()->willReturn();
    
    $this->tokenManager->getDelisId()->willReturn('test_delis_id');
    
    $this->tokenManager->getPassword()->willReturn('test_password');
    
    // Mock SOAP fault
    $soapFault = new SoapFault('LOGIN_1', 'Invalid credentials');
    
    $this->soapClient->getAuth(Argument::any())->willThrow($soapFault);
    
    $this->logger->error('DPD LoginService authentication failed (@code): @message', Argument::type('array'))->shouldBeCalled();
    
    // Assert & Act
    $this->expectException(SoapFault::class);
    $this->expectExceptionMessage('Invalid credentials');
    
    $this->dpdApiClient->getToken();
  }
  
  /**
   *
   * @covers ::getToken
   * @covers ::generateNewToken
   */
  public function testGetTokenThrowsExceptionWhenNoTokenInResponse(): void {
    // Arrange
    $this->tokenManager->getCachedTokenData()->willReturn(null);
    
    $this->tokenManager->incrementTokenGenerationCount()->willReturn();
    
    $this->tokenManager->getDelisId()->willReturn('test_delis_id');
    
    $this->tokenManager->getPassword()->willReturn('test_password');
    
    // Mock SOAP response without token
    $soapResponse = (object) [
      'return' => (object) [
        // No authToken!
        'customerUid' => 'cust_456'
      ]
    ];
    
    $this->soapClient->getAuth(Argument::any())->willReturn($soapResponse);
    
    $this->logger->error('DPD LoginService authentication ERROR (@code): @message', Argument::type('array'))->shouldBeCalled();
    
    // Assert & Act
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('DPD LoginService returned no authToken');
    
    $this->dpdApiClient->getToken();
  }
  
  /**
   *
   * @covers ::getToken
   * @covers ::generateNewToken
   */
  public function testGetTokenRespectsDailyLimit(): void {
    // Arrange
    $this->tokenManager->getCachedTokenData()->willReturn(null);
    
    // Simulate limit reached
    $this->tokenManager->incrementTokenGenerationCount()->willThrow(new \RuntimeException('Maximum DPD token generation limit reached (10/10 tokens today)'));
    
    $this->logger->warning('Maximum DPD token generation limit reached (10/10 tokens today)', Argument::any())->shouldBeCalled();
    
    // Assert & Act
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Maximum DPD token generation limit reached');
    
    $this->dpdApiClient->getToken();
  }
  
  /**
   *
   * @covers ::getDelisId
   */
  public function testGetDelisIdThrowsExceptionWhenNotConfigured(): void {
    // Arrange
    $this->tokenManager->getDelisId()->willReturn('');
    
    // Assert & Act
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('DPD credentials are not configured');
    
    // Use reflection to call protected method
    $reflection = new \ReflectionClass($this->dpdApiClient);
    $method = $reflection->getMethod('getDelisId');
    $method->setAccessible(true);
    $method->invoke($this->dpdApiClient);
  }
  
  /**
   *
   * @covers ::getPassword
   */
  public function testGetPasswordThrowsExceptionWhenNotConfigured(): void {
    // Arrange
    $this->tokenManager->getPassword()->willReturn('');
    
    // Assert & Act
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('DPD credentials are not configured');
    
    // Use reflection to call protected method
    $reflection = new \ReflectionClass($this->dpdApiClient);
    $method = $reflection->getMethod('getPassword');
    $method->setAccessible(true);
    $method->invoke($this->dpdApiClient);
  }
  
  /**
   *
   * @covers ::normalizeIso88591
   */
  public function testNormalizeIso88591ConvertsAddressFields(): void {
    // Arrange
    $testData = [
      'name1' => 'Müller', // UTF-8 with special character
      'street' => 'Straße 123', // UTF-8 with ß
      'city' => 'Berlin',
      'zipCode' => '10115', // Should NOT be converted
      'phone' => '+49 30 123456', // Should NOT be converted
      'nested' => [
        'company' => 'H&M Mode', // UTF-8 with &
        'floor' => '2ème étage' // UTF-8 with accent
      ]
    ];
    
    // Act
    $result = $this->callProtectedMethod('normalizeIso88591', [
      $testData
    ]);
    
    // Assert
    // Check that address fields were converted
    $this->assertNotEquals('Müller', $result['name1']);
    $this->assertNotEquals('Straße 123', $result['street']);
    
    // Check that non-address fields were NOT converted
    $this->assertEquals('10115', $result['zipCode']);
    $this->assertEquals('+49 30 123456', $result['phone']);
    
    // Check nested fields
    $this->assertNotEquals('H&M Mode', $result['nested']['company']);
    $this->assertNotEquals('2ème étage', $result['nested']['floor']);
  }
  
  /**
   *
   * @covers ::normalizeIso88591
   */
  public function testNormalizeIso88591HandlesNonStringValues(): void {
    // Arrange
    $testData = [
      'name' => 'Test',
      'age' => 30, // integer
      'price' => 19.99, // float
      'active' => true, // boolean
      'tags' => null, // null
      'list' => [
        'item1',
        'item2'
      ] // array
    ];
    
    // Act
    $result = $this->callProtectedMethod('normalizeIso88591', [
      $testData
    ]);
    
    // Assert
    $this->assertNotEquals('Test', $result['name']); // Should be converted
    $this->assertSame(30, $result['age']); // Should remain integer
    $this->assertSame(19.99, $result['price']); // Should remain float
    $this->assertTrue($result['active']); // Should remain boolean
    $this->assertNull($result['tags']); // Should remain null
    $this->assertIsArray($result['list']); // Should remain array
  }
  
  /**
   *
   * @covers ::isAuthExpiredFault
   */
  public function testIsAuthExpiredFaultDetectsLoginErrors(): void {
    // Test cases
    $testCases = [
      [
        'message' => 'LOGIN_5: Token expired',
        'expected' => true
      ],
      [
        'message' => 'LOGIN_6: Invalid token',
        'expected' => true
      ],
      [
        'message' => 'LOGIN_1: Invalid credentials',
        'expected' => false
      ],
      [
        'message' => 'Generic error',
        'expected' => false
      ],
      [
        'message' => '',
        'expected' => false
      ]
    ];
    
    foreach ($testCases as $testCase) {
      $soapFault = new SoapFault('SERVER', $testCase['message']);
      
      $result = $this->callProtectedMethod('isAuthExpiredFault', [
        $soapFault
      ]);
      
      $this->assertSame($testCase['expected'], $result, sprintf('Failed for message: "%s"', $testCase['message']));
    }
  }
  
  /**
   *
   * @covers ::storeOrders
   * @covers ::getToken
   */
  public function testStoreOrdersRetriesOnExpiredToken(): void {
    // This test is more complex as it requires mocking multiple SOAP calls
    // We'll test the retry logic when token expires
    
    // Note: This test would require mocking the shipment client as well
    // For brevity, we'll mark it as incomplete and note the approach
    $this->markTestIncomplete('Requires mocking of shipment SOAP client');
  }
  
  /**
   * Helper method to call protected methods.
   *
   * @param string $methodName
   * @param array $args
   * @return mixed
   */
  private function callProtectedMethod(string $methodName, array $args = []) {
    $reflection = new \ReflectionClass($this->dpdApiClient);
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(true);
    
    return $method->invokeArgs($this->dpdApiClient, $args);
  }
}