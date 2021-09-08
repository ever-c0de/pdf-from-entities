<?php

namespace Drupal\pdf_from_entities\Service;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\node\Entity\Node;
use mikehaertl\wkhtmlto\Pdf;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Render\RendererInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides a service for PDF generating.
 */
class PdfFromEntitiesPdfService {

  /**
   * Date storage.
   *
   * @var \Drupal\Core\Datetime\DrupalDateTime
   */
  protected $date;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The file logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new State.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Psr\Log\LoggerInterface $logger
   *   The file logger channel.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(RendererInterface $renderer, LoggerInterface $logger, MessengerInterface $messenger) {
    $this->date = new DrupalDateTime();
    $this->date = $this->date->getTimestamp();
    $this->renderer = $renderer;
    $this->logger = $logger;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('logger.channel.default'),
      $container->get('messenger')
    );
  }

  /**
   * Generate PDF file with configured settings.
   *
   * @param \Drupal\node\Entity\Node $node
   *   Loaded node.
   * @param string $folder
   *   Destination folder.
   *
   * @property \Drupal\file_entity\Entity\FileEntity entity
   *
   * @return bool
   *   Status of PDF.
   */
  public function generatePdf(Node $node, string $folder): bool {
    $template = $this->generateTemplate($node);

    if (!$template) {
      return FALSE;
    }

    $pdf = new Pdf($template);
    $pdf->setOptions($this->getPdfOptions());
    $pdf_name = preg_replace('/\s+/', '_', $node->getTitle());

    // Save the PDF as file in specific folder.
    if (!$pdf->saveAs($folder . $pdf_name . '.pdf')) {
      $error = $pdf->getError();
      $this->logger->error($error);
      $this->messenger->addError('Unexpected error happened while creating PDF. Please check logs.');
    }

    unset($pdf);
    return TRUE;
  }

  /**
   * Generate template for specific node.
   *
   * @param \Drupal\node\Entity\Node $node
   *   Loaded node.
   *
   * @return string
   *   Rendered template in HTML format.
   */
  public function generateTemplate(Node $node): string {
    $date = $this->date;
    $fields = $node->getFields();
    $admin_fields = ['title', 'created', 'changed', 'uid'];
    // Variable for admin fields in template.
    $info = NULL;
    // Variable for content fields in template.
    $content = NULL;

    /** @var \Drupal\Core\Field\FieldItemList $field */
    foreach ($fields as $field) {
      $name = $field->getName();
      if (in_array($name, $admin_fields)) {
        $info[$name] = $field->getValue()[0];
      }
      // Get rendered field to manipulate with it from markup.
      $full_field = $field->view('full');
      if ($field->getFieldDefinition()->getType() == 'image') {
        $image_url = $field->entity->getFileUri();
        $image_url = file_create_url($image_url);
        // Add image full url to image-field render array to show it in PDF.
        $full_field['image_url'] = $image_url;
      }
      // Add needed content fields as variable.
      if (!empty($full_field['#theme']) && !in_array($full_field['#field_name'], $admin_fields)) {
        $content[] = $full_field;
      }
    }

    $template = [
      '#theme' => 'pdf_from_entities_pdf',
      '#info' => $info,
      '#content' => $content,
      '#date' => $date,
    ];

    $html = $this->renderer
      ->renderRoot($template)
      ->__toString();
    $result = new Response($html, 200);

    return $result->getContent();
  }

  /**
   * Get options for PDF object.
   */
  protected function getPdfOptions(): array {
    return [
      0 => 'no-outline',
      'page-size' => 'A4',
      2 => 'disable-smart-shrinking',
      'encoding' => 'UTF-8',
      'user-style-sheet' => DRUPAL_ROOT . '/modules/custom/pdf_from_entities/style/css/style.css',
      'margin-top' => 10,
      'margin-right' => 10,
      'margin-bottom' => 10,
      'margin-left' => 10,
    ];
  }

}
