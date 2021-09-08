<?php

namespace Drupal\pdf_from_entities\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Render\RendererInterface;
use mikehaertl\wkhtmlto\Pdf;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 *
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
   * Constructs a new State.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(RendererInterface $renderer) {
    $this->date = new DrupalDateTime();
    $this->date = $this->date->getTimestamp();
    $this->renderer = $renderer;
  }

  /**
   *
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer')
    );
  }

  /**
   *
   */
  public function generatePdf($node, $folder) {
    $template = $this->generateTemplate($node);

    if (!$template) {
      return FALSE;
    }

    $pdf = new Pdf($template);
    $pdf->setOptions($this->getPdfOptions());
    $pdf_name = preg_replace('/\s+/', '_', $node->getTitle());

    // Save the PDF.
    if (!$pdf->saveAs($folder . $pdf_name . '.pdf')) {
      $error = $pdf->getError();
    }

    unset($pdf);
    return TRUE;
  }

  /**
   *
   */
  public function generateTemplate($node) {
    $date = $this->date;
    $fields = $node->getFields();
    $admin_fields = ['title', 'created', 'changed', 'uid'];

    /** @var \Drupal\Core\Field\FieldItemList $field */
    foreach ($fields as $field) {
      $name = $field->getName();
      if (in_array($name, $admin_fields)) {
        $info[$name] = $field->getValue()[0];
      }

      $full_field = $field->view('full');
      if ($field->getFieldDefinition()->getType() == 'image') {
        $image_url = $field->entity->getFileUri();
        $image_url = file_create_url($image_url);
        $full_field['image_url'] = $image_url;
      }

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
   *
   */
  protected function getPdfOptions() {
    return [
      'no-outline',
      'page-size' => 'A4',
      'disable-smart-shrinking',
      'encoding' => 'UTF-8',
      'user-style-sheet' => DRUPAL_ROOT . '/modules/custom/pdf_from_entities/style/css/style.css',
      'margin-top' => 10,
      'margin-right' => 10,
      'margin-bottom' => 0,
      'margin-left' => 10,
    ];
  }

}
