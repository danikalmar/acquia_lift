<?php

namespace Drupal\acquia_lift\Service\Context;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;
use Drupal\acquia_lift\Service\Helper\SettingsHelper;

class PageContext {
  /**
   * Engagement score's default value.
   */
  const ENGAGEMENT_SCORE_DEFAULT = 1;

  /**
   * Lift JavaScript file name.
   */
  const LIFT_JS_FILENAME = 'lift.js';

  /**
   * Field mappings.
   *
   * @var array
   */
  private $fieldMappings;

  /**
   * Udf Person mappings.
   *
   * @var array
   */
  private $udfPersonMappings;

  /**
   * Udf Event mappings.
   *
   * @var array
   */
  private $udfEventMappings;

  /**
   * Udf Touch mappings.
   *
   * @var array
   */
  private $udfTouchMappings;

  /**
   * Thumbnail config.
   *
   * @var array
   */
  private $thumbnailConfig;

  /**
   * Taxonomy term storage.
   *
   * @var \Drupal\taxonomy\TermStorageInterface
   */
  private $taxonomyTermStorage;

  /**
   * Page context, with default value.
   *
   * @var array
   */
  private $pageContext = [
    'content_title' => 'Untitled',
    'content_type' => 'page',
    'page_type' => 'content page',
    'content_section' => '',
    'content_keywords' => '',
    'post_id' => '',
    'published_date' => '',
    'thumbnail_url' => '',
    'persona' => '',
    'engagement_score' => SELF::ENGAGEMENT_SCORE_DEFAULT,
    'author' => '',
  ];

  /**
   * Assets URL.
   *
   * @var array
   */
  private $assetsUrl;

  /**
   * Credential mapping.
   *
   * @var array
   */
  private static $CREDENTIAL_MAPPING = [
    'account_id' => 'account_id',
    'site_id' => 'site_id',
    'content_origin' => 'contentOrigin',
    'assets_url' => 'liftAssetsURL',
    'decision_api_url' => 'liftDecisionAPIURL',
    'oauth_url' => 'authEndpoint',
  ];

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Controller\TitleResolverInterface $title_resolver
   *   The title resolver.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack, RouteMatchInterface $route_match, TitleResolverInterface $title_resolver) {
    // Get all our settings.
    $settings = $config_factory->get('acquia_lift.settings');

    // Set Credential information.
    $credential_settings = $settings->get('credential');
    $this->assetsUrl = $credential_settings['assets_url'];
    $this->setPageContextCredential($credential_settings);

    // Set mapping information.
    $this->fieldMappings = $settings->get('field_mappings');
    $this->udfPersonMappings = $settings->get('udf_person_mappings');
    $this->udfEventMappings = $settings->get('udf_touch_mappings');
    $this->udfTouchMappings = $settings->get('udf_event_mappings');

    // Set thumbnail configuration.
    $this->thumbnailConfig = $settings->get('thumbnail');

    // Set advanced configuration.
    $this->setPageContextAdvancedConfiguration($settings->get('advanced'));

    // Set taxonomyTermStorage.
    $this->taxonomyTermStorage = $entity_type_manager->getStorage('taxonomy_term');

    // Set page context
    $request = $request_stack->getCurrentRequest();
    $route = $route_match->getRouteObject();
    $this->setPageContextByNode($request);
    $this->setPageContextTitle($request, $route, $title_resolver);
  }

  /**
   * Set page context by Node.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   */
  private function setPageContextByNode(Request $request) {
    // If not a request to node, do nothing.
    if (!$request->attributes->has('node')) {
      return;
    }

    $node = $request->attributes->get('node');
    if (empty($node) || ! $node instanceof NodeInterface) {
      return;
    }

    // Otherwise, set page context by node.
    $this->setNodeData($node);
    $this->setThumbnailUrl($node);
    $this->setFields($node);
  }

  /**
   * Set page context - title.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param \Symfony\Component\Routing\Route $route
   *   The route object.
   * @param \Drupal\Core\Controller\TitleResolverInterface $titleResolver
   *   The title resolver.
   */
  private function setPageContextTitle(Request $request, Route $route, TitleResolverInterface $titleResolver) {
    // Find title.
    $title = $titleResolver->getTitle($request, $route);

    // Set title.
    // If markup, strip tags and convert to string.
    if (is_array($title) && isset($title['#markup']) && isset($title['#allowed_tags'])) {
      $allowed_tags = empty($title['#allowed_tags']) ? '' : '<' . implode('><', $title['#allowed_tags']) . '>';
      $title = strip_tags($title['#markup'], $allowed_tags);
    }
    // If still an array or empty, leave title at "Untitled".
    if (is_array($title) || empty($title)) {
      return;
    }
    // Otherwise set title.
    $this->pageContext['content_title'] = $title;
  }

  /**
   * Set page context - credential.
   *
   * @param array $credential_settings
   *   Credential settings array.
   */
  private function setPageContextCredential($credential_settings) {
    foreach (SELF::$CREDENTIAL_MAPPING as $credential_key => $tag_name) {
      if (isset($credential_settings[$credential_key])) {
        $this->pageContext[$tag_name] = $credential_settings[$credential_key];
      }
    };
  }

  /**
   * Set any Lift configuration values to the page context.
   *
   * @param $settings \Drupal\Core\Config\ImmutableConfig
   *   The lift settings values.
   */
  private function setPageContextAdvancedConfiguration($advanced_settings) {
    $replacement_mode = $advanced_settings['content_replacement_mode'];
    if (SettingsHelper::isValidContentReplacementMode($replacement_mode)) {
      $this->pageContext['contentReplacementMode'] = $replacement_mode;
    }
  }

  /**
   * Set Node data.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node.
   */
  private function setNodeData(NodeInterface $node) {
    $this->pageContext['content_type'] = $node->getType();
    $this->pageContext['content_title'] = $node->getTitle();
    $this->pageContext['published_date'] = $node->getCreatedTime();
    $this->pageContext['post_id'] = $node->id();
    $this->pageContext['author'] = $node->getOwner()->getUsername();
    $this->pageContext['page_type'] = 'node page';
  }

  /**
   * Set thumbnail URL.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node.
   */
  private function setThumbnailUrl(NodeInterface $node) {
    $node_type = $node->getType();

    // Don't set, if no thumbnail has been configured.
    if (!isset($this->thumbnailConfig[$node_type])) {
      return;
    }
    $node_type_thumbnail_config = $this->thumbnailConfig[$node_type];
    $thumbnail_config_array = explode('->', $node_type_thumbnail_config['field']);

    $entity = $node;
    foreach ($thumbnail_config_array as $field_key) {
      // Don't set, if node has no such field or field has no such entity.
      if (empty($entity->{$field_key}->entity) ||
        method_exists($entity->{$field_key}, 'isEmpty') && $entity->{$field_key}->isEmpty()
      ) {
        return;
      }
      $entity = $entity->{$field_key}->entity;
    }

    if ($entity->bundle() !== 'file') {
      return;
    }
    $fileUri = $entity->getFileUri();

    // Process Image style.
    $image_style = ImageStyle::load($node_type_thumbnail_config['style']);
    // Return empty if no such image style.
    if (empty($image_style)) {
      return;
    }

    // Generate image URL.
    $thumbnail_uri = $image_style->buildUrl($fileUri);
    $this->pageContext['thumbnail_url'] = file_create_url($thumbnail_uri);
  }

  /**
   * Set fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node.
   */
  private function setFields(NodeInterface $node) {
    $available_field_vocabulary_names = $this->getAvailableFieldVocabularyNames($node);
    $vocabulary_term_names = $this->getVocabularyTermNames($node);

    // Find Field Term names.
    foreach ($available_field_vocabulary_names as $page_context_name => $vocabulary_names) {
      $field_term_names = $this->getFieldTermNames($vocabulary_names, $vocabulary_term_names);
      // Only set when the value is a populated array
      // Empty arrays return as false in PHP.
      if (!empty($field_term_names)) {
        $this->pageContext[$page_context_name] = implode(',', $field_term_names);
      }
    }
  }

  /**
   * Get available Fields and their vocabulary names within the node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node.
   * @return array
   *   Node's available Fields' Vocabularies names.
   */
  private function getAvailableFieldVocabularyNames(NodeInterface $node) {
    $available_field_vocabulary_names = [];
    $available_field_vocabulary_fields = [];

    // Regular field mapping
    foreach ($this->fieldMappings as $page_context_name => $field_name) {
      if(!isset($node->{$field_name})) {
        continue;
      }
      // Add this field to the list of fields to parse with their corresponding
      // page context name;
      if (!isset($available_field_vocabulary_fields[$field_name])) {
        $available_field_vocabulary_fields[$field_name] = [];
      }
      $available_field_vocabulary_fields[$field_name][] = $page_context_name;
    }
    // The following 3 mappings have all the same structure with different array
    // id's so we can merge them without conflict.
    $udf_mappings = array_merge($this->udfPersonMappings, $this->udfTouchMappings, $this->udfEventMappings);
    foreach ($udf_mappings as $page_context_name => $properties) {
      if(!isset($node->{$properties['value']})) {
        continue;
      }
      // Add this field to the list of fields to parse with their corresponding
      // page context name;
      if (!isset($available_field_vocabulary_fields[$properties['value']])) {
        $available_field_vocabulary_fields[$properties['value']] = [];
      }
      $available_field_vocabulary_fields[$properties['value']][] = $page_context_name;
    }

    foreach($available_field_vocabulary_fields as $field_name => $page_contexts) {
      $vocabulary_names = $node->{$field_name}->getSetting('handler_settings')['target_bundles'];
      foreach ($page_contexts as $page_context_name) {
        $available_field_vocabulary_names[$page_context_name] = $vocabulary_names;
      }
    }

    return $available_field_vocabulary_names;
  }

  /**
   * Get Vocabularies' Term names.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node.
   * @return array
   *   Vocabularies' Term names.
   */
  private function getVocabularyTermNames(NodeInterface $node) {
    // Find the node's terms.
    $terms = $this->taxonomyTermStorage->getNodeTerms([$node->id()]);
    $node_terms = isset($terms[$node->id()]) ? $terms[$node->id()] : [];

    // Find the term names.
    $vocabulary_term_names = [];
    foreach ($node_terms as $term) {
      $vocabulary_id = $term->getVocabularyId();
      $term_name = $term->getName();
      $vocabulary_term_names[$vocabulary_id][] = $term_name;
    }
    return $vocabulary_term_names;
  }

  /**
   * Get Field's Term names.
   *
   * @param array $vocabulary_names
   *   Vocabulary names.
   * @param array $vocabulary_term_names
   *   Vocabulary Term names.
   * @return array
   *   Field Term names.
   */
  private function getFieldTermNames($vocabulary_names, $vocabulary_term_names) {
    $field_term_names = [];
    foreach ($vocabulary_names as $vocabulary_name) {
      if (!isset($vocabulary_term_names[$vocabulary_name])) {
        continue;
      }
      $field_term_names = array_merge($field_term_names, $vocabulary_term_names[$vocabulary_name]);
    }
    return array_unique($field_term_names);
  }

  /**
   * Get the render array for a single meta tag.
   *
   * @param string $name
   *   The name for the meta tag
   * @param string $content
   *   The content for the meta tag
   * @return array
   *   The render array
   */
  private function getMetaTagRenderArray($name, $content) {
    return [
      '#type' => 'html_tag',
      '#tag' => 'meta',
      '#attributes' => [
        'itemprop' => 'acquia_lift:' . $name,
        'content' => $content,
      ],
    ];
  }

  /**
   * Get the render array for a JavaScript tag.
   *
   * @return array
   *   The render array
   */
  private function getJavaScriptTagRenderArray() {
    return [
      [
        '#tag' => 'script',
        '#attributes' => [
          'src' => $this->assetsUrl . '/' . SELF::LIFT_JS_FILENAME,
        ],
      ],
      'acquia_lift_javascript',
    ];
  }

  /**
   * Populate page's HTML head.
   *
   * @param &$htmlHead
   *   The HTML head that is to be populated.
   */
  public function populateHtmlHead(&$htmlHead) {
    // Attach Lift's metatags.
    foreach ($this->pageContext as $name => $content) {
      $renderArray = $this->getMetaTagRenderArray($name, $content);
      // To generate meta tags within HTML head, Drupal requires this precise
      // format of render array.
      $htmlHead[] = [$renderArray, $name];
    }

    // Attach Lift's JavaScript.
    $htmlHead[] = $this->getJavaScriptTagRenderArray();
  }
}
