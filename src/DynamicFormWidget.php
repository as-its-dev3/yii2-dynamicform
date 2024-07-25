<?php
/**
 * @link      https://github.com/wbraganca/yii2-dynamicform
 * @copyright Copyright (c) 2014 Wanderson Bragança
 * @license   https://github.com/wbraganca/yii2-dynamicform/blob/master/LICENSE
 */

namespace wbraganca\dynamicform;

use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\base\InvalidConfigException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * yii2-dynamicform is a widget for the Yii2 framework that allows for cloning form elements in a nested manner,
 * maintaining accessibility. It supports dynamic insertion and deletion of form items, with optional PJAX support for AJAX scenarios.
 *
 * @author Wanderson Bragança <wanderson.wbc@gmail.com>
 * @link https://github.com/as-its-dev3/yii2-dynamicform
 */
class DynamicFormWidget extends \yii\base\Widget
{
    /**
     * @var string The name of the widget.
     */
    const WIDGET_NAME = 'dynamicform';
    /**
     * @var string The container CSS class for the widget.
     */
    public $widgetContainer;
    /**
     * @var string The body CSS class of the widget where items will be cloned.
     */
    public $widgetBody;
    /**
     * @var string The item CSS class that will be cloned.
     */
    public $widgetItem;
    /**
     * @var string The limit of how many times an item can be cloned.
     */
    public $limit = 999;
    /**
     * @var string The CSS class or ID of the insert button.
     */
    public $insertButton;
    /**
     * @var string The CSS class or ID of the delete button.
     */
    public $deleteButton;
    /**
     * @var string 'bottom' or 'top'; the position where a new item will be inserted relative to other items.
     */
    public $insertPosition = 'bottom';
    /**
     * @var \yii\base\Model|\yii\db\ActiveRecord The model used for the form. This model is utilized for generating field IDs and names.
     */
    public $model;
    /**
     * @var string The ID of the form.
     */
    public $formId;
    /**
     * @var array The fields to be validated. These should correspond to attributes of the model.
     */
    public $formFields;
    /**
     * @var integer The minimum number of items required. Defaults to 1.
     */
    public $min = 1;
    /**
     * @var bool Whether to use PJAX. This should be set to true when using PJAX to ensure proper functionality.
     */
    public $usePjax = false;
    /**
     * @var null|string Whether to enforce a specific encoding. If set to null, it means no specific encoding is designated and will follow the `Yii::$app->charset` setting.
     */
    public $charset = null;
    /**
     * @var string Options for the widget in JSON format. This is for internal use.
     */
    protected $_options;
    /**
     * @var array Valid positions for inserting a new item. For internal use.
     */
    protected $_insertPositions = ['bottom', 'top'];
    /**
     * @var string The name of the hashed global variable storing the plugin options. For internal use.
     */
    protected $_hashVar;
    /**
     * @var string The JSON encoded options. For internal use.
     */
    protected $_encodedOptions = '';

    /**
     * Initializes the widget by setting up configurations and validating the provided options.
     *
     * @throws \yii\base\InvalidConfigException If configuration validations fail.
     */
    public function init()
    {
        // The parent init call is made to ensure proper initialization sequence.
        parent::init();

        if (empty($this->widgetContainer) || (preg_match('/^\w{1,}$/', $this->widgetContainer) === 0))
        {
            throw new InvalidConfigException('Invalid configuration to property "widgetContainer". 
                Allowed only alphanumeric characters plus underline: [A-Za-z0-9_]');
        }
        if (empty($this->widgetBody))
        {
            throw new InvalidConfigException("The 'widgetBody' property must be set.");
        }
        if (empty($this->widgetItem))
        {
            throw new InvalidConfigException("The 'widgetItem' property must be set.");
        }
        if (empty($this->model) || !$this->model instanceof \yii\base\Model)
        {
            throw new InvalidConfigException("The 'model' property must be set and must extend from '\\yii\\base\\Model'.");
        }
        if (empty($this->formId))
        {
            throw new InvalidConfigException("The 'formId' property must be set.");
        }
        if (empty($this->insertPosition) || ! in_array($this->insertPosition, $this->_insertPositions))
        {
            throw new InvalidConfigException("Invalid configuration to property 'insertPosition' (allowed values: 'bottom' or 'top')");
        }
        if (empty($this->formFields) || !is_array($this->formFields))
        {
            throw new InvalidConfigException("The 'formFields' property must be set.");
        }
        if (empty($this->charset))
        {
            $this->charset = Yii::$app->charset;
        }

        $this->initOptions();
    }

    /**
     * Initializes the widget options. Sets up necessary configurations for dynamic form functionality.
     */
    protected function initOptions()
    {
        $this->_options['widgetContainer'] = $this->widgetContainer;
        $this->_options['widgetBody']      = $this->widgetBody;
        $this->_options['widgetItem']      = $this->widgetItem;
        $this->_options['limit']           = $this->limit;
        $this->_options['insertButton']    = $this->insertButton;
        $this->_options['deleteButton']    = $this->deleteButton;
        $this->_options['insertPosition']  = $this->insertPosition;
        $this->_options['formId']          = $this->formId;
        $this->_options['min']             = $this->min;
        $this->_options['fields']          = [];

        foreach ($this->formFields as $field)
        {
            $this->_options['fields'][] = [
                'id' => Html::getInputId($this->model, '[{}]' . $field),
                'name' => Html::getInputName($this->model, '[{}]' . $field)
            ];
        }

        ob_start();
        ob_implicit_flush(false);
    }

    /**
     * Registers the plugin options in a hashed JavaScript variable for use on the client side.
     *
     * @param \yii\web\View $view The View object in which the JavaScript variable will be registered.
     */
    protected function registerOptions($view)
    {
        $view->registerJs("var {$this->_hashVar} = {$this->_encodedOptions};\n", $view::POS_HEAD);
    }

    /**
     * Generates a hashed variable name to store the options, to ensure uniqueness.
     */
    protected function hashOptions()
    {
        $this->_encodedOptions = Json::encode($this->_options);
        $this->_hashVar = self::WIDGET_NAME . '_' . hash('crc32', $this->_encodedOptions);
    }

    /**
     * Returns the name of the hashed variable.
     *
     * @return string The hashed variable name.
     */
    protected function getHashVarName()
    {
        if (isset(Yii::$app->params[self::WIDGET_NAME][$this->widgetContainer]))
        {
            return Yii::$app->params[self::WIDGET_NAME][$this->widgetContainer];
        }

        return $this->_hashVar;
    }

    /**
     * Registers the actual widget in the Yii application parameters to ensure it is only initialized once.
     *
     * @return boolean Whether the widget was successfully registered.
     */
    public function registerHashVarWidget()
    {
        if (!isset(Yii::$app->params[self::WIDGET_NAME][$this->widgetContainer]))
        {
            Yii::$app->params[self::WIDGET_NAME][$this->widgetContainer] = $this->_hashVar;
            return true;
        }

        return false;
    }

    /**
     * Registers the required assets for the widget, such as JavaScript handlers for the insert and delete actions.
     *
     * @param \yii\web\View $view The View object in which the assets will be registered.
     */
    public function registerAssets($view)
    {
        // Register the main asset bundle for the dynamic form widget.
        DynamicFormAsset::register($view);

        // Setup JavaScript for handling the click event on the insert button. This includes cloning the form element,
        // adding it to the form, and reinitializing any necessary JavaScript components or plugins.
        // The JavaScript snippet varies slightly depending on whether PJAX is being used.
        if ($this->usePjax)
        {
            // PJAX-specific setup: disables previous click handlers to prevent duplicate handlers after PJAX updates.
            $js = 'jQuery("#' . $this->formId . '").off("click").on("click", "' . $this->insertButton . '", function(e) {'. "\n";
        }
        else
        {
            // Regular setup: adds click handler directly.
            $js = 'jQuery("#' . $this->formId . '").on("click", "' . $this->insertButton . '", function(e) {'. "\n";
        }
        $js .= "    e.preventDefault();\n";
        $js .= '    jQuery(".' .  $this->widgetContainer . '").triggerHandler("beforeInsert", [jQuery(this)]);' . "\n";
        $js .= '    jQuery(".' .  $this->widgetContainer . '").yiiDynamicForm("addItem", '. $this->_hashVar . ", e, jQuery(this));\n";
        $js .= "});\n";
        $view->registerJs($js, $view::POS_READY);

        // Registers the JavaScript for handling the click event on the delete button.
        // This typically involves removing the form element and updating any necessary states or counters.
        $js = 'jQuery("#' . $this->formId . '").on("click", "' . $this->deleteButton . '", function(e) {'. "\n";
        $js .= "    e.preventDefault();\n";
        $js .= '    jQuery(".' .  $this->widgetContainer . '").yiiDynamicForm("deleteItem", '. $this->_hashVar . ", e, jQuery(this));\n";
        $js .= "});\n";
        $view->registerJs($js, $view::POS_READY);

        // Finally, initialize the dynamic form functionality with the configured options by invoking the yiiDynamicForm jQuery plugin.
        $js = 'jQuery("#' . $this->formId . '").yiiDynamicForm(' . $this->_hashVar .');' . "\n";
        $view->registerJs($js, $view::POS_LOAD);
    }

    /**
     * Executes the widget. This method is responsible for rendering the widget's content and initializing
     * the dynamic form functionality. It captures the widget's output, processes it (e.g., converting HTML entities),
     * and finally displays the processed content within a container div that is recognized by the JavaScript functionality.
     * 
     * @return void
     */
    public function run()
    {
        // Capture the output that may have been generated during widget initialization.
        $content = ob_get_clean();

        // Check if the content contains HTML entities.
        if (preg_match('/&[#a-zA-Z0-9]+;/', $content))
        {
            // If the content contains HTML entities, convert them to ensure proper display.
            if (version_compare(phpversion(), '8.2', '>=')) {
                $content = mb_encode_numericentity(htmlspecialchars_decode(htmlentities($content, ENT_NOQUOTES, 'UTF-8', false), ENT_NOQUOTES), [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
            } else {
                $content = mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8');
            }
        }

        // Use Crawler to process the content further, if necessary.
        $crawler = new Crawler();
        $crawler->addHTMLContent($content, $this->charset);
        // Extract the first widget item from the content to use as a template.
        $results = $crawler->filter($this->widgetItem);
        $document = new \DOMDocument('1.0', $this->charset);
        $document->appendChild($document->importNode($results->first()->getNode(0), true));
        // Save the processed template back to the widget's options.
        $this->_options['template'] = trim($document->saveHTML());

        // If 'min' option is set to zero and the model is a new record,
        // remove all initial items from the content.
        if (isset($this->_options['min']) && $this->_options['min'] === 0 && $this->model->isNewRecord)
        {
            $content = $this->removeItems($content);
        }

        // Generate and store a unique hash for this widget instance's options.
        $this->hashOptions();
        // Retrieve the view object to register assets.
        $view = $this->getView();
        // Register this widget instance, ensuring it's initialized only once.
        $widgetRegistered = $this->registerHashVarWidget();
        // Retrieve the unique hash variable name for this instance.
        $this->_hashVar = $this->getHashVarName();

        // If the widget was successfully registered,
        // proceed with registering JavaScript options and assets.
        if ($widgetRegistered)
        {
            $this->registerOptions($view);
            $this->registerAssets($view);
        }

        // Finally, output the widget's content wrapped in a div,
        // with the necessary data attributes for JavaScript interaction.
        echo Html::tag('div', $content, [
            'class' => $this->widgetContainer,
            'data-dynamicform' => $this->_hashVar,
        ]);
    }

    /**
     * Removes items from the HTML content of the widget body.
     * This is used to start with a specific number of items,
     * which can be zero or more, depending on the 'min' configuration.
     * 
     * @param string $content The HTML content from which items will be removed.
     * @return string The modified HTML content with items removed.
     */
    private function removeItems($content)
    {
        $crawler = new Crawler();
        $crawler->addHTMLContent($content, $this->charset);
        $crawler
            ->filter($this->widgetItem)
            ->each(function ($nodes) {
                foreach ($nodes as $node) {
                    $node->parentNode->removeChild($node);
                }
            });

        return $crawler->html();
    }
}
