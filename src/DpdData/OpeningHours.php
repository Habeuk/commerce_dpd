<?php

namespace Drupal\commerce_dpd\DpdData;

use Drupal\commerce_dpd\DpdHelper\BaseData;
use Drupal\commerce_dpd\Dto\Attribute\DpdField;
use Drupal\commerce_dpd\Dto\Hydrator\DpdHydrator;

class OpeningHours extends BaseData {
  #[DpdField(source: 'weekday', type: 'string')]
  private readonly ?string $weekday;
  #[DpdField(source: 'weekdayNum', type: 'int')]
  private readonly ?int $weekdayNum;
  #[DpdField(source: 'openMorning', type: 'string')]
  private readonly ?string $openMorning;
  #[DpdField(source: 'closeMorning', type: 'string')]
  private readonly ?string $closeMorning;
  #[DpdField(source: 'openAfternoon', type: 'string')]
  private readonly ?string $openAfternoon;
  #[DpdField(source: 'closeAfternoon', type: 'string')]
  private readonly ?string $closeAfternoon;
  #[DpdField(source: 'dayOff', type: 'bool')]
  private readonly ?bool $dayOff;
  
  /**
   * Constructeur privé – instanciation via hydrator uniquement.
   */
  private function __construct() {
  }
  
  // === Getters ===
  public function getWeekday(): ?string {
    return $this->weekday;
  }
  
  public function getWeekdayNum(): ?int {
    return $this->weekdayNum;
  }
  
  public function getOpenMorning(): ?string {
    return $this->openMorning;
  }
  
  public function getCloseMorning(): ?string {
    return $this->closeMorning;
  }
  
  public function getOpenAfternoon(): ?string {
    return $this->openAfternoon;
  }
  
  public function getCloseAfternoon(): ?string {
    return $this->closeAfternoon;
  }
  
  public function isDayOff(): bool {
    return $this->dayOff;
  }
  
  // === Méthodes métier ===
  public function getFormatted(): string {
    if ($this->dayOff) {
      return 'Fermé';
    }
    
    if ($this->openMorning === null || $this->closeMorning === null) {
      return '';
    }
    
    if ($this->openAfternoon === null || $this->closeAfternoon === null || ($this->openAfternoon === '00:00' && $this->closeAfternoon === '00:00')) {
      return sprintf('%s - %s', $this->openMorning, $this->closeMorning);
    }
    
    return sprintf('%s - %s / %s - %s', $this->openMorning, $this->closeMorning, $this->openAfternoon, $this->closeAfternoon);
  }
  
  /**
   * Vérifie si le point est ouvert à une heure donnée.
   */
  public function isOpenAtTime(string $time): bool {
    if ($this->dayOff) {
      return false;
    }
    $timeStr = str_replace(':', '', $time);
    // Vérifier les horaires du matin
    if ($this->openMorning !== '00:00' && $this->closeMorning !== '00:00') {
      $openMorning = str_replace(':', '', $this->openMorning);
      $closeMorning = str_replace(':', '', $this->closeMorning);
      
      if ($timeStr >= $openMorning && $timeStr <= $closeMorning) {
        return true;
      }
    }
    
    // Vérifier les horaires de l'après-midi
    if ($this->openAfternoon !== '00:00' && $this->closeAfternoon !== '00:00') {
      $openAfternoon = str_replace(':', '', $this->openAfternoon);
      $closeAfternoon = str_replace(':', '', $this->closeAfternoon);
      
      if ($timeStr >= $openAfternoon && $timeStr <= $closeAfternoon) {
        return true;
      }
    }
    return false;
  }
  
  /**
   * Retourne tous les créneaux horaires.
   */
  public function getTimeSlots(): array {
    if ($this->dayOff) {
      return [];
    }
    
    $slots = [];
    
    if ($this->openMorning !== '00:00' && $this->closeMorning !== '00:00') {
      $slots[] = [
        'open' => $this->openMorning,
        'close' => $this->closeMorning,
        'period' => 'morning'
      ];
    }
    
    if ($this->openAfternoon !== '00:00' && $this->closeAfternoon !== '00:00') {
      $slots[] = [
        'open' => $this->openAfternoon,
        'close' => $this->closeAfternoon,
        'period' => 'afternoon'
      ];
    }
    
    return $slots;
  }
  
  public function toArray(): array {
    return [
      'weekday' => $this->weekday,
      'weekday_num' => $this->weekdayNum,
      'open_morning' => $this->openMorning,
      'close_morning' => $this->closeMorning,
      'open_afternoon' => $this->openAfternoon,
      'close_afternoon' => $this->closeAfternoon,
      'day_off' => $this->dayOff
    ];
  }
  
  // === Factories ===
  public static function createFromDpdData(array|object $dataRaw, DpdHydrator $hydrator): self {
    return $hydrator->hydrate(self::class, $dataRaw);
  }
  
  public static function createCollectionFromDpdData(array $hoursData): array {
    $instances = [];
    $hydrator = new DpdHydrator();
    foreach ($hoursData as $key => $hourData) {
      $instances[$key] = self::createFromDpdData($hourData, $hydrator);
    }
    return $instances;
  }
}
