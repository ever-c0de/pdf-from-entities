<?php

namespace Drupal\pdf_from_entities\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\File\FileSystemInterface;
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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  protected function getEnititiesFolder() :string {
    return 'temporary://pdf_from_entities/';
  }

  /**
   * PdfFromEntitiesForm constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *    The file system service.
   */
  public function __construct(EntityTypeBundleInfo $entity_type_bundle_info, NodeStorageInterface $node_storage, FileSystemInterface $file_system) {
    $this->nodeBundles = $entity_type_bundle_info->getBundleInfo('node');
    array_walk($this->nodeBundles, function (&$a) {
      $a = $a['label'];
    });
    $this->nodeStorage = $node_storage;
    $this->fileSystem = $file_system;
    $path_pdf_folder = $this->getEnititiesFolder();
    $this->fileSystem->prepareDirectory($path_pdf_folder, FileSystemInterface::CREATE_DIRECTORY);
    $this->batchBuilder = new BatchBuilder();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager')->getStorage('node'),
      $container->get('file_system')
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

    $test = \Drupal::service('pdf_from_entities.generate_pdf');

    $test->getTestPDF();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity_types = array_filter($form_state->getValue(['entity_types']));

    foreach ($entity_types as $entity_type) {
      $path_entity_folder =  'temporary://pdf_from_entities/' . $entity_type . '/';
      $this->fileSystem->prepareDirectory($path_entity_folder, FileSystemInterface::CREATE_DIRECTORY);
    }

    $nodes = $this->getNodes($entity_types);

      $this->batchBuilder
        ->setTitle($this->t("Processing"))
        ->setInitMessage($this->t('Initializing.'))
        ->setProgressMessage($this->t('Completed @current of @total.'))
        ->setErrorMessage($this->t('An error has occurred.'));

    $this->batchBuilder->addOperation([$this, 'processItems'], [$nodes]);
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
          $this->processItem($item);

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

    $test = \Drupal::service('pdf_from_entities.generate_pdf');

    $test->getTestPDF();

    // TODO: Call the service that will be generating pdf-files from node;
    // TODO: Create logic in case If service return NULL;
  }

  /**
   * Finished callback for batch.
   */
  public function finished($success, $results, $operations) {
    $this->fileSystem->deleteRecursive($this->getEnititiesFolder());

    $message = $this->t('Number of nodes affected by batch: @count', [
      '@count' => $results['processed'],
    ]);

    $this->messenger()
      ->addStatus($message);
  }


  /**
   * Load all nids for specific types.
   *
   * @return array
   *   An array with nids.
   */
  public function getNodes($type) {
    return $this->nodeStorage->getQuery()
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('type', $type, 'IN')
      ->execute();
  }

}
