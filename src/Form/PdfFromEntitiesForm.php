<?php

namespace Drupal\pdf_from_entities\Form;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Archiver\ArchiverManager;
use Drupal\pdf_from_entities\Service\PdfFromEntitiesPdfService;
use ZipArchive;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a base form for generation PDF's from entities.
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

  /**
   * The archiver manager.
   *
   * @var \Drupal\Core\Archiver\ArchiverManager
   */
  protected $archiverManager;

  /**
   * The pdf service.
   *
   * @var \Drupal\pdf_from_entities\Service\PdfFromEntitiesPdfService
   */
  protected $pdfService;

  /**
   * Get the base entity folder.
   */
  protected function getEntitiesFolder() :string {
    return 'temporary://pdf_from_entities/';
  }

  /**
   * PdfFromEntitiesForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfo $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\node\NodeStorageInterface $node_storage
   *   The node storage service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Archiver\ArchiverManager $archiver_manager
   *   The archiver manager service.
   * @param \Drupal\pdf_from_entities\Service\PdfFromEntitiesPdfService $pdf_service
   *   The pdf service.
   */
  public function __construct(EntityTypeBundleInfo $entity_type_bundle_info, NodeStorageInterface $node_storage, FileSystemInterface $file_system, ArchiverManager $archiver_manager, PdfFromEntitiesPdfService $pdf_service) {
    $this->nodeBundles = $entity_type_bundle_info->getBundleInfo('node');
    array_walk($this->nodeBundles, function (&$a) {
      $a = $a['label'];
    });
    $this->nodeStorage = $node_storage;
    $this->fileSystem = $file_system;
    $this->archiverManager = $archiver_manager;
    $this->batchBuilder = new BatchBuilder();
    $this->pdfService = $pdf_service;
    $entities_folder = $this->getEntitiesFolder();
    $this->fileSystem->prepareDirectory($entities_folder, FileSystemInterface::CREATE_DIRECTORY);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): PdfFromEntitiesForm {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager')->getStorage('node'),
      $container->get('file_system'),
      $container->get('plugin.manager.archiver'),
      $container->get('pdf_from_entities.generate_pdf')
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
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate Archive'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity_types = array_filter($form_state->getValue(['entity_types']));

    // Prepare folder for each entity type.
    foreach ($entity_types as $entity_type) {
      $this->getNodeFolder($entity_type);
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

  /**
   * Processor for batch operations.
   *
   * @param array $items
   *   Nodes id's.
   * @param array $context
   *   Batch process.
   */
  public function processItems(array $items, array &$context) {
    // Elements per operation.
    $limit = 10;

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
  public function processItem($nid) :bool {
    $pdf_service = $this->pdfService;

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->nodeStorage->load($nid);
    // Prepare the folder for node type.
    $folder = $this->getNodeFolder($node->getType());

    if (!($pdf_service->generatePdf($node, $folder))) {
      $link = Url::fromRoute('entity.node.canonical', ['node' => $node->id()]);
      $message = $this->t('Cannot create template for @title node.', [
        '@title' => Link::fromTextAndUrl($node->getTitle(), $link)->toString(),
      ]);
      $this->messenger()
        ->addError($message);

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Finished callback for batch.
   */
  public function finished($success, $results, $operations) {
    $time = new DrupalDateTime();
    $entities_folder = $this->fileSystem->realpath(($this->getEntitiesFolder()));
    // Creating unique name for ZIP archive.
    $zip_name = "Content_types_{$time->format('m_d_o_H_i_s')}" . '.zip';

    // Prepare file to create ZIP instance from it.
    $file = $this->fileSystem->saveData('', "temporary://{$zip_name}", FileSystemInterface::EXISTS_REPLACE);
    $file = $this->fileSystem->realpath($file);

    if ($this->getZip($entities_folder, $file)) {
      $link = Url::fromUserInput("/{$this->fileSystem->getTempDirectory()}/{$zip_name}");
      $message = $this->t('Link to your ZIP archive containing nodes in PDF format : @link', [
        '@link' => Link::fromTextAndUrl($this->t('click'), $link)->toString(),
      ]);

      $this->messenger()
        ->addStatus($message);
    }
    else {
      $message = $this->t('Some unexpected error occurred while creating ZIP archive. Please, check logs.');

      $this->messenger()
        ->addError($message);
    }

    $this->fileSystem->deleteRecursive($this->getEntitiesFolder());
  }

  /**
   * Load all nids for specific type(s).
   *
   * @return array
   *   An array with nids.
   */
  public function getNodes(array $type): array {
    return $this->nodeStorage->getQuery()
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('type', $type, 'IN')
      ->execute();
  }

  /**
   * Create folder for specific entity type.
   *
   * @param string $entity_type
   *   Entity type.
   *
   * @return string
   *   Path to the entity folder.
   */
  public function getNodeFolder(string $entity_type) :string {
    $path_entity_folder = $this->getEntitiesFolder() . $entity_type . '/';
    if (!($this->fileSystem->prepareDirectory($path_entity_folder, FileSystemInterface::CREATE_DIRECTORY))) {
      $message = $this->t('Cannot create directory for @entity entity type. Please, check your Temporary directory in @link.', [
        '@entity' => $entity_type,
        '@link' => Link::createFromRoute('file system settings', 'system.file_system_settings')->toString(),
      ]);

      $this->messenger()->addError($message);
      return FALSE;
    }
    return $path_entity_folder;
  }

  /**
   * Create ZIP archive recursively from the folder.
   *
   * @param string $source
   *   Path to the source folder.
   * @param string $destination
   *   Path to the destination folder.
   *
   * @return bool
   *   Status of zip.
   */
  protected function getZip(string $source, string $destination): bool {
    if (!extension_loaded('zip') || !file_exists($source)) {
      return FALSE;
    }

    $zip = $this->archiverManager->getInstance(['filepath' => $this->fileSystem->realpath($destination)]);
    $zip = $zip->getArchive();

    if (!$zip->open($destination, ZIPARCHIVE::CREATE)) {
      return FALSE;
    }

    $source = str_replace('\\', '/', realpath($source));

    if (is_dir($source) === TRUE) {
      $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);

      foreach ($files as $file) {
        $file = str_replace('\\', '/', $file);

        // Ignore "." and ".." folders.
        if (in_array(substr($file, strrpos($file, '/') + 1), ['.', '..'])) {
          continue;
        }

        $file = realpath($file);

        if (is_dir($file) === TRUE) {
          $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
        }
        elseif (is_file($file) === TRUE) {
          $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
        }
      }
    }
    elseif (is_file($source) === TRUE) {
      $zip->addFromString(basename($source), file_get_contents($source));
    }

    return $zip->close();
  }

}
