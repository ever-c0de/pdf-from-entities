<?php

namespace Drupal\pdf_from_entities\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
class PdfFromEntitiesForm extends FormBase {

  /**
   * Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * An array with available node types.
   *
   * @var array|mixed
   */
  protected $nodeBundles;

  /**
   * Batch Builder.
   *
   * @var \Drupal\Core\Batch\BatchBuilder
   */
  protected $batchBuilder;

  /**
   * Node storage.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected $nodeStorage;

  /**
   * PdfFromEntitiesForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   EntityTypeManager construct.
   */
  public function __construct(EntityTypeBundleInfo $entity_type_bundle_info, NodeStorageInterface $node_storage, EntityTypeManagerInterface $entity_type_manager) {
    $this->nodeBundles = $entity_type_bundle_info->getBundleInfo('node');
    array_walk($this->nodeBundles, function (&$a) {
      $a = $a['label'];
    });
    $this->entityTypeManager = $entity_type_manager;
    $this->nodeStorage = $node_storage;
    $this->batchBuilder = new BatchBuilder();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager')->getStorage('node'),
      $container->get('entity_type.manager')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'pdf_from_entities_module_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Choose entity-types which nodes will be converted to PDF'),
      '#options' => $this->nodeBundles,
      '#required' => TRUE
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['generate_archive'] = [
      '#type' => 'submit',
      '#name' => 'generate_archive',
      '#value' => $this->t('Generate Archive'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity_types = array_filter($form_state->getValue(['entity_types']));
      $this->batchBuilder
        ->setTitle($this->t("Processing"))
        ->setInitMessage($this->t('Initializing.'))
        ->setProgressMessage($this->t('Completed @current of @total.'))
        ->setErrorMessage($this->t('An error has occurred.'));

    $this->batchBuilder->addOperation([$this, 'processItems'], [$entity_types]);
    $this->batchBuilder->setFinishCallback([$this, 'finished']);
    batch_set($this->batchBuilder->toArray());
  }

  public function processItems($items, array &$context) {
    // Elements per operation.
    $limit = 50;

    // Set default progress values.
    if (empty($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = count($items);
    }

    // Save items to array which will be changed during processing.
    if (empty($context['sandbox']['items'])) {
      $context['sandbox']['items'] = $items;
    }

    $counter = 0;
    if (!empty($context['sandbox']['items'])) {
      // Remove already processed items.
      if ($context['sandbox']['progress'] != 0) {
        array_splice($context['sandbox']['items'], 0, $limit);
      }

      foreach ($context['sandbox']['items'] as $item) {
        if ($counter != $limit) {
          $nodes = $this->getNodes($item);
          // TODO: Prepare the folder for each content-type.
          foreach ($nodes as $node) {
          $this->processItem($node);

          $counter++;
          $context['sandbox']['progress']++;

          $context['message'] = $this->t('Now processing node :progress of :count', [
            ':progress' => $context['sandbox']['progress'],
            ':count' => $context['sandbox']['max'],
          ]);

          // Increment total processed item values. Will be used in finished
          // callback.
          $context['results']['processed'] = $context['sandbox']['progress'];
          }
        }
      }
    }

    // If not finished all tasks, we count percentage of process. 1 = 100%.
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Process single item.
   *
   * @param int|string $nid
   *   An id of Node.
   */
  public function processItem($nid) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->nodeStorage->load($nid);
    $needed_fields = $node->getFields();
    // TODO: Call the service that will be generating pdf-files from node
  }

  /**
   * Finished callback for batch.
   */
  public function finished($success, $results, $operations) {
    $message = $this->t('Number of nodes affected by batch: @count', [
      '@count' => $results['processed'],
    ]);

    $this->messenger()
      ->addStatus($message);
  }


  /**
   * Load all nids for specific type.
   *
   * @return array
   *   An array with nids.
   */
  public function getNodes($type) {
    return $this->nodeStorage->getQuery()
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('type', $type)
      ->execute();
  }

}
