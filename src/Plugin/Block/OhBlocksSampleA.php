<?php

declare(strict_types = 1);

namespace Drupal\oh_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateHelper;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\oh\OhDateRange;
use Drupal\oh\OhOccurrence;
use Drupal\oh\OhOpeningHoursInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sample Block A.
 *
 * This creates a block that outputs similar to:
 *
 * <code>
 * |-----------|-----------------------|
 * | Day       | Hours                 |
 * |-----------|-----------------------|
 * | Sunday    | Closed                |
 * | Monday    | Open 9:00am to 5:00pm |
 * | Tuesday   | Open 9:00am to 5:00pm |
 * | Wednesday | Open 9:00am to 5:00pm |
 * | Thursday  | Open 9:00am to 5:00pm |
 * | Friday    | Open 9:00am to 5:00pm |
 * | Saturday  | Closed                |
 * |-----------|-----------------------|
 * </code>
 *
 * @Block(
 *   id = "oh_blocks_sample_a",
 *   admin_label = @Translation("Sample Block A"),
 *   category = @Translation("OH Sample Blocks"),
 *   context = {
 *     "entity" = @ContextDefinition("entity",
 *       label = @Translation("Entity"),
 *       required = TRUE
 *     )
 *   }
 * )
 */
class OhBlocksSampleA extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The opening hours service.
   *
   * @var \Drupal\oh\OhOpeningHoursInterface
   */
  protected $openingHours;

  /**
   * Constructs a new OhBlocksSampleA block.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\oh\OhOpeningHoursInterface $openingHours
   *   Opening hours service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, OhOpeningHoursInterface $openingHours) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->openingHours = $openingHours;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('oh.opening_hours')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $entity = $this->getContextValue('entity');
    assert($entity instanceof EntityInterface);

    $cacheability = (new CacheableMetadata())
      // Ranges vary by time zone.
      ->addCacheContexts(['timezone'])
      // Automatically expire after a sensible time frame.
      ->setCacheMaxAge(3600);

    $build = [];

    $start = new DrupalDateTime('today 0:00');
    // One week into the future.
    $end = (clone $start)->modify('+1 week');
    $range = new OhDateRange($start, $end);

    $occurrences = $this->openingHours->getOccurrences($entity, $range);

    // Merge cachability.
    foreach ($occurrences as $occurrence) {
      $cacheability->addCacheableDependency($occurrence);
    }

    // Sort by time.
    usort($occurrences, [OhOccurrence::class, 'sort']);

    // Fill days with empty day numbers.
    // 0-6: Sunday-Saturday.
    $days = array_fill_keys(range(0, 6), []);

    // Sort occurrences into days.
    foreach ($occurrences as $occurrence) {
      $dayNumber = (int) $occurrence->getStart()->format('w');
      $days[$dayNumber][] = $occurrence;
    }

    // Render days.
    $rows = [];
    foreach ($days as $dayNumber => $occurrences) {
      $row = [];
      $row['day']['data']['#markup'] = DateHelper::weekDays()[$dayNumber];

      if (count($occurrences) > 0) {
        $row['hours']['data'] = [
          '#theme' => 'item_list',
          '#items' => array_map(function (OhOccurrence $occurrence) {
            $status = $occurrence->isOpen() ? $this->t('Open') : $this->t('Closed');
            $start = $occurrence->getStart()->format('g:ia');
            $end = $occurrence->getEnd()->format('g:ia');
            $messages = count($occurrence->getMessages()) > 0
              ? ' (' . implode(' ', array_unique($occurrence->getMessages())) . ')'
              : '';
            return sprintf('%s %s to %s%s', $status, $start, $end, $messages);
          }, $occurrences),
        ];
      }
      else {
        $row['hours']['data']['#markup'] = $this->t('Closed');
      }

      $rows[] = $row;
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'day' => $this->t('Day'),
        'hours' => $this->t('Hours'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('There are no opening hours for @entity', [
        '@entity' => $entity->label(),
      ]),
    ];

    $cacheability->applyTo($build);
    return $build;
  }

}
