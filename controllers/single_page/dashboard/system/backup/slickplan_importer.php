<?php
namespace Concrete\Package\SlickplanImporter\Controller\SinglePage\Dashboard\System\Backup;

use \Concrete\Core\Attribute\Set as AttributeSet;
use \Concrete\Core\File\Importer as FileImporter;
use \Concrete\Core\File\Version as FileVersion;
use \Concrete\Core\Page\Controller\DashboardPageController;
use \Concrete\Core\Page\Type\Composer\Control\BlockControl;
use \Concrete\Core\Page\Type\Composer\Control\Control as PageTypeComposerControl;
use \Concrete\Core\Page\Type\Composer\Control\Type\BlockType;
use \Config;
use \Core;
use \DomDocument;
use \Exception;
use \File;
use \FilePermissions;
use \Loader;
use \Page;
use \PageTemplate;
use \PageType;
use \UserList;

function_exists('ob_start') and ob_start();
function_exists('set_time_limit') and set_time_limit(600);

class SlickplanImporter extends DashboardPageController
{

    /**
     * Import options
     *
     * @var array
     */
    public $options = array(
        'titles' => '',
        'content' => '',
        'post_type' => '',
        'content_files' => false,
        'users' => array(),
        'internal_links' => array(),
        'imported_pages' => array(),
    );

    /**
     * Import results
     *
     * @var array
     */
    public $summary = array();

    /**
     * Array of imported files
     *
     * @var array
     */
    private $_files = array();

    /**
     * If page has unparsed internal pages
     *
     * @var bool
     */
    private $_has_unparsed_internal_links = false;

    /**
     * Upload page
     */
    public function import()
    {
        if (isset($_FILES['slickplan_file']['tmp_name'], $_FILES['slickplan_file']['error'])) {
            if ($this->token->validate('slickplan-import')) {
                if ($_FILES['slickplan_file']['error'] !== UPLOAD_ERR_OK) {
                    $error = FileImporter::getErrorMessage($_FILES['slickplan_file']['error']);
                    if (!$error) {
                        $error = FileImporter::getErrorMessage(FileImporter::E_PHP_NO_FILE);
                    }
                    $this->error->add($error);
                }
                if (!$this->error->has()) {
                    try {
                        $_SESSION['slickplan_importer']
                            = $this->_parseSlickplanXml(file_get_contents($_FILES['slickplan_file']['tmp_name']));
                        $this->redirect('/dashboard/system/backup/slickplan_importer/options');
                    } catch (Exception $e) {
                        $this->error->add($e->getMessage());
                    }
                }
            } else {
                $this->error->add($this->token->getErrorMessage());
            }
        }
    }

    /**
     * Import options page
     */
    public function options()
    {
        if (isset($_SESSION['slickplan_importer'])
            and $this->_isCorrectSlickplanXmlFile($_SESSION['slickplan_importer'], true)
        ) {
            $xml = $_SESSION['slickplan_importer'];
            $title = (isset($xml['settings']['title']) and $xml['settings']['title'])
                ? $xml['settings']['title']
                : $xml['title'];
            Loader::model('user_list');
            $user_list = new UserList();
            $users = $user_list->get();
            $this->set('slickplan_title', $title);
            $this->set('slickplan_users', $users);
            $this->set('slickplan_xml', $xml);
            if ($this->isPost() and $this->post('slickplan_importer') and is_array($this->post('slickplan_importer'))) {
                $form = $this->post('slickplan_importer');
                if (isset($form['settings_title']) and $form['settings_title']) {
                    Config::save('concrete.site', $title);
                }
                $this->options = array(
                    'titles' => isset($form['titles_change']) ? $form['titles_change'] : '',
                    'content' => isset($form['content']) ? $form['content'] : '',
                    'content_files' => (
                        isset($form['content'], $form['content_files'])
                        and $form['content'] === 'contents'
                        and $form['content_files']
                    ),
                    'users' => isset($form['users_map']) ? $form['users_map'] : array(),
                    'internal_links' => array(),
                    'imported_pages' => array(),
                );
                if ($this->options['content_files']) {
                    $xml['import_options'] = $this->options;
                    $_SESSION['slickplan_importer'] = $xml;
                    $this->redirect('/dashboard/system/backup/slickplan_importer/ajax');
                } else {
                    foreach (array('home', '1', 'util', 'foot') as $type) {
                        if (isset($xml['sitemap'][$type]) and is_array($xml['sitemap'][$type])) {
                            $this->_importPages($xml['sitemap'][$type], $xml['pages']);
                        }
                    }
                    $this->_checkForInternalLinks();
                    $xml['summary'] = implode($this->summary);
                    $_SESSION['slickplan_importer'] = $xml;
                    $this->redirect('/dashboard/system/backup/slickplan_importer/done');
                }
            } else {
                $no_of_files = 0;
                $filesize_total = array();
                if (isset($xml['pages']) and is_array($xml['pages'])) {
                    foreach ($xml['pages'] as $page) {
                        if (isset($page['contents']['body']) and is_array($page['contents']['body'])) {
                            foreach ($page['contents']['body'] as $body) {
                                if (isset($body['content']['type']) and $body['content']['type'] === 'library') {
                                    ++$no_of_files;
                                }
                                if (isset($body['content']['file_size'], $body['content']['file_id']) and $body['content']['file_size']) {
                                    $filesize_total[$body['content']['file_id']] = (int)$body['content']['file_size'];
                                }
                            }
                        }
                    }
                }
                $filesize_total = (int)array_sum($filesize_total);
                $size = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
                $factor = (int)floor((strlen($filesize_total) - 1) / 3);
                $filesize_total = round($filesize_total / pow(1024, $factor)) . $size[$factor];
                $this->set('no_of_files', $no_of_files);
                $this->set('filesize_total', $filesize_total);
            }
        } else {
            $this->redirect('/dashboard/system/backup/slickplan_importer/import');
        }
    }

    /**
     * AJAX import page
     */
    public function ajax()
    {
        if (isset($_SESSION['slickplan_importer'], $_SESSION['slickplan_importer']['import_options'])
            and $this->_isCorrectSlickplanXmlFile($_SESSION['slickplan_importer'], true)
        ) {
            $xml = $_SESSION['slickplan_importer'];
            $this->options = $xml['import_options'];
            $this->set('slickplan_xml', $xml);
            $this->set('slickplan_html', $this->_getSummaryRow(array(
                'title' => '{title}',
                'loading' => 1,
            )));
            if ($this->isPost() and is_array($this->post('slickplan'))) {
                $form = $this->post('slickplan');
                $result = array();
                if (isset($xml['import_options'])) {
                    if (isset($xml['pages'][$form['page']]) and is_array($xml['pages'][$form['page']])) {
                        $mlid = (isset($form['mlid']) and $form['mlid'])
                            ? $form['mlid']
                            : 0;
                        $page = $this->_importPage($xml['pages'][$form['page']], $mlid);
                        $result = $page;
                        $result['html'] = $this->_getSummaryRow($page);
                    }
                    if (isset($form['last']) and $form['last']) {
                        $result['last'] = $form['last'];
                        $this->_checkForInternalLinks();
                        unset($_SESSION['slickplan_importer']);
                    }
                    else {
                        $xml['import_options'] = $this->options;
                        $_SESSION['slickplan_importer'] = $xml;
                    }
                }
                ob_clean();
                header('Content-Type: application/json');
                echo json_encode($result);
                exit;
            }
        } else {
            $this->redirect('/dashboard/system/backup/slickplan_importer/import');
        }
    }

    /**
     * Import finished page
     */
    public function done()
    {
        if (isset($_SESSION['slickplan_importer']['summary']) and $_SESSION['slickplan_importer']['summary']) {
            $this->set('slickplan_xml', $_SESSION['slickplan_importer']);
            unset($_SESSION['slickplan_importer']);
        } else {
            $this->redirect('/dashboard/system/backup/slickplan_importer/import');
        }
    }

    /**
     * Parse Slickplan's XML file. Converts an XML DOMDocument to an array.
     *
     * @param $input_xml
     * @return array
     * @throws Exception
     */
    private function _parseSlickplanXml($input_xml)
    {
        $input_xml = trim($input_xml);
        if (substr($input_xml, 0, 5) === '<?xml') {
            $xml = new DomDocument('1.0', 'UTF-8');
            $xml->xmlStandalone = false;
            $xml->formatOutput = true;
            $xml->loadXML($input_xml);
            if (isset($xml->documentElement->tagName) and $xml->documentElement->tagName === 'sitemap') {
                $array = $this->_parseSlickplanXmlNode($xml->documentElement);
                if ($this->_isCorrectSlickplanXmlFile($array)) {
                    if (isset($array['diagram'])) {
                        unset($array['diagram']);
                    }
                    if (isset($array['section']['options'])) {
                        $array['section'] = array($array['section']);
                    }
                    $array['sitemap'] = $this->_getMultidimensionalArrayHelper($array);
                    $array['users'] = array();
                    $array['pages'] = array();
                    foreach ($array['section'] as $section_key => $section) {
                        if (isset($section['cells']['cell']) and is_array($section['cells']['cell'])) {
                            foreach ($section['cells']['cell'] as $cell_key => $cell) {
                                if (
                                    isset($section['options']['id'], $cell['level'])
                                    and $cell['level'] === 'home'
                                    and $section['options']['id'] !== 'svgmainsection'
                                ) {
                                    unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                                }
                                if (isset(
                                    $cell['contents']['assignee']['@value'],
                                    $cell['contents']['assignee']['@attributes']
                                )) {
                                    $array['users'][$cell['contents']['assignee']['@value']]
                                        = $cell['contents']['assignee']['@attributes'];
                                }
                                if (isset($cell['@attributes']['id'])) {
                                    $array['pages'][$cell['@attributes']['id']] = $cell;
                                }
                            }
                        }
                    }
                    unset($array['section']);
                    return $array;
                }
            }
        }
        throw new Exception('Invalid file format.');
    }

    /**
     * Add a file to Media Library from URL
     *
     * @param $url
     * @param array $attrs Assoc array of attributes [title, alt, description, file_name]
     * @return bool|string
     */
    private function _addMedia($url, array $attrs = array())
    {
        if (!$this->options['content_files']) {
            return false;
        }
        if (!isset($attrs['file_name']) or !$attrs['file_name']) {
            $url = parse_url($url);
            $attrs['file_name'] = basename($url['path']);
        }
        $file = preg_replace('/[^a-z0-9\._\-]+/i', '', $attrs['file_name']);
        $prefix = '_slickplan_';
        $file = $file ?
            sys_get_temp_dir() . $prefix . $file
            : tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($file, file_get_contents($url));
        $result = array(
            'filename' => $attrs['file_name'],
        );
        try {
            $fp = FilePermissions::getGlobal();
            if (!$fp->canAddFiles()) {
                throw new Exception(FileImporter::getErrorMessage(FileImporter::E_PHP_FILE_ERROR_DEFAULT));
            }
            $cf = Loader::helper('file');
            $fr = null;
            if (!$fp->canAddFileType($cf->getExtension($result['filename']))) {
                $resp = FileImporter::E_FILE_INVALID_EXTENSION;
            } else {
                $fi = new FileImporter();
                $resp = $fi->import($file, $result['filename'], $fr);
            }
            if (!($resp instanceof FileVersion)) {
                throw new Exception(FileImporter::getErrorMessage($resp));
            }
            $fi = $resp->getFile();
            $fv = $fi->getRecentVersion();
            $url = $fv->getURL();
            if (isset($attrs['title']) and $attrs['title']) {
                $fv->updateTitle($attrs['title']);
            } elseif (isset($attrs['alt']) and $attrs['alt']) {
                $fv->updateTitle($attrs['alt']);
            }
            if (isset($attrs['description']) and $attrs['description']) {
                $fv->updateDescription($attrs['description']);
            }
            $result['url'] = $url;
            $this->_files[] = $result;
            return $url;
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }
        unlink($file);
        $this->_files[] = $result;
        return false;
    }

    /**
     * Import pages into Drupal.
     *
     * @param array $structure
     * @param array $pages
     * @param int $parent_id
     */
    private function _importPages(array $structure, array $pages, $parent_id = 0)
    {
        foreach ($structure as $page) {
            if (isset($page['id'], $pages[$page['id']])) {
                $result = $this->_importPage($pages[$page['id']], $parent_id);
                if (
                    isset($page['childs'], $result['mlid'])
                    and $result['mlid']
                    and is_array($page['childs'])
                    and count($page['childs'])
                ) {
                    $this->_importPages($page['childs'], $pages, $result['mlid']);
                }
            }
        }
    }

    /**
     * Import single page into Drupal.
     *
     * @param array $data
     * @param int $parent_id
     */
    private function _importPage(array $data, $parent_id = 0)
    {
        $this->_files = array();

        if (!$parent_id) {
            $parent_id = HOME_CID;
        }
        $page_parent = Page::getByID($parent_id);
        $page_type = PageType::getByHandle('page');
        $template = $page_type->getPageTypeDefaultPageTemplateObject();
        $content_block = false;
        $controls = PageTypeComposerControl::getList($page_type);
        foreach ($controls as $control) {
            if ($control instanceof BlockControl) {
                $control_obj = $control->getBlockTypeObject();
                if ($control_obj->getBlockTypeHandle() === 'content') {
                    $content_block = $control;
                    break;
                }
            }
        }

        $page = array(
            'cName' => $this->_getFormattedTitle($data),
        );

        // Set url slug
        if (isset($data['contents']['url_slug']) and $data['contents']['url_slug']) {
            $page['cHandle'] = $data['contents']['url_slug'];
        }

        // Page description
        if (isset($data['desc']) and !empty($data['desc'])) {
            $page['cDescription'] = $data['desc'];
        }

        // Set post author
        if (isset(
            $data['contents']['assignee']['@value'],
            $this->options['users'][$data['contents']['assignee']['@value']]
        )) {
            $page['uID'] = $this->options['users'][$data['contents']['assignee']['@value']];
        }

        try {
            if (!$page_parent) {
                throw new Exception('Cannot find parent page');
            }
            if (!$page_type) {
                throw new Exception('Cannot find page type: \'page\'');
            }
            if (!$template) {
                throw new Exception('Cannot find page type\'s default template');
            }
            $entry = $page_parent->add($page_type, $page, $template);
            if ($entry) {
                // Set post content
                $page_content = '';
                if ($this->options['content'] === 'desc') {
                    if (isset($data['desc']) and !empty($data['desc'])) {
                        $page_content = $data['desc'];
                    }
                } elseif ($this->options['content'] === 'contents') {
                    if (
                        isset($data['contents']['body'])
                        and is_array($data['contents']['body'])
                        and count($data['contents']['body'])
                    ) {
                        $page_content = $this->_getFormattedContent($data['contents']['body']);
                    }
                }

                if ($page_content) {
                    $this->_has_unparsed_internal_links = false;
                    if ($controls) {
                        // Check if page has internal links, we need to replace them later
                        $updated_content = $this->_parseInternalLinks($page_content);
                        if ($updated_content) {
                            $page_content = $updated_content;
                        }

                        $content_data = array(
                            'content' => $page_content,
                        );
                        $content_block->publishToPage($entry, $content_data, $controls);
                    } else {
                        throw new Exception('Content block not found');
                    }
                }

                // Set the SEO meta values
                if (
                    isset($data['contents']['meta_title'])
                    or isset($data['contents']['meta_description'])
                    or isset($data['contents']['meta_focus_keyword'])
                ) {
                    $as = AttributeSet::getByHandle('seo');
                    $attributes = $as->getAttributeKeys();
                    foreach ($attributes as $ak) {
                        $key = $ak->getKeyHandle();
                        if (substr($key, 0, 5) === 'meta_') {
                            if ($key === 'meta_keywords') {
                                $key = 'meta_focus_keyword';
                            }
                            if (isset($data['contents'][$key]) and $data['contents'][$key]) {
                                $ak->setAttribute($entry, $data['contents'][$key]);
                            }
                        }
                    }
                }
            } else {
                throw new Exception('Error while creating a page');
            }
            $return = array(
                'ID' => $entry->getCollectionID(),
                'title' => $page['cName'],
                'url' => $entry->getCollectionLink(),
                'mlid' => $entry->getCollectionID(),
                'files' => $this->_files,
            );

            // Save page permalink
            if (isset($data['@attributes']['id'])) {
                $this->options['imported_pages'][$data['@attributes']['id']] = $return['url'];
            }

            // Check if page has unparsed internal links, we need to replace them later
            if ($this->_has_unparsed_internal_links) {
                $this->options['internal_links'][] = $return['ID'];
            }
        } catch (Exception $e) {
            $return = array(
                'title' => $page['cName'],
                'error' => $e->getMessage(),
            );
        }
        $this->summary[] = $this->_getSummaryRow($return);
        return $return;
    }

    /**
     * Get HTML of a summary row
     *
     * @param array $page
     * @param null $id
     * @return string
     */
    private function _getSummaryRow(array $page)
    {
        $html = '<div style="margin: 10px 0;">Importing „<b>' . $page['title'] . '</b>”&hellip;<br />';
        if (isset($page['error']) and $page['error']) {
            $html .= '<span style="color: #e00"><i class="fa fa-fw fa-times"></i> ' . $page['error'] . '</span>';
        } elseif (isset($page['url'])) {
            $html .= '<i class="fa fa-fw fa-check" style="color: #0d0"></i> '
                . '<a href="' . $page['url'] . '">' . array_pop(explode('index.php', $page['url'])) . '</a>';
        } elseif (isset($page['loading']) and $page['loading']) {
            $html .= '<i class="fa fa-fw fa-refresh fa-spin"></i>';
        }
        if (isset($page['files']) and is_array($page['files']) and count($page['files'])) {
            $files = array();
            foreach ($page['files'] as $file) {
                if (isset($file['url']) and $file['url']) {
                    $files[] = '<i class="fa fa-fw fa-check" style="color: #0d0"></i> <a href="'
                        . $file['url'] . '" target="_blank">' . $file['filename'] . '</a>';
                } elseif (isset($file['error']) and $file['error']) {
                    $files[] = '<span style="color: #e00"><i class="fa fa-fw fa-times"></i> '
                        . $file['filename'] . ' - ' . $file['error'] . '</span>';
                }
            }
            $html .= '<div style="border-left: 5px solid rgba(0, 0, 0, 0.05); margin-left: 5px; '
                . 'padding: 5px 0 5px 11px;">Files:<br />' . implode('<br />', $files) . '</div>';
        }
        $html .= '<div>';
        return $html;
    }

    /**
     * Get formatted HTML content.
     *
     * @param array $content
     */
    private function _getFormattedContent(array $contents)
    {
        $post_content = array();
        foreach ($contents as $type => $content) {
            if (isset($content['content'])) {
                $content = array($content);
            }
            foreach ($content as $element) {
                if (!isset($element['content'])) {
                    continue;
                }
                $html = '';
                switch ($type) {
                    case 'wysiwyg':
                        $html .= $element['content'];
                        break;
                    case 'text':
                        $html .= htmlspecialchars($element['content']);
                        break;
                    case 'image':
                        if (isset($element['content']['type'], $element['content']['url'])) {
                            $attrs = array(
                                'alt' => isset($element['content']['alt'])
                                    ? $element['content']['alt']
                                    : '',
                                'title' => isset($element['content']['title'])
                                    ? $element['content']['title']
                                    : '',
                                'file_name' => isset($element['content']['file_name'])
                                    ? $element['content']['file_name']
                                    : '',
                            );
                            if ($element['content']['type'] === 'library') {
                                $src = $this->_addMedia($element['content']['url'], $attrs);
                            } else {
                                $src = $element['content']['url'];
                            }
                            if ($src and is_string($src)) {
                                $html .= '<img src="' . htmlspecialchars($src)
                                    . '" alt="' . htmlspecialchars($attrs['alt'])
                                    . '" title="' . htmlspecialchars($attrs['title']) . '" />';
                            }
                        }
                        break;
                    case 'video':
                    case 'file':
                        if (isset($element['content']['type'], $element['content']['url'])) {
                            $attrs = array(
                                'description' => isset($element['content']['description'])
                                    ? $element['content']['description']
                                    : '',
                                'file_name' => isset($element['content']['file_name'])
                                    ? $element['content']['file_name']
                                    : '',
                            );
                            if ($element['content']['type'] === 'library') {
                                $src = $this->_addMedia($element['content']['url'], $attrs);
                                $name = basename($src);
                            } else {
                                $src = $element['content']['url'];
                                $name = $src;
                            }
                            if ($src and is_string($src)) {
                                $name = $attrs['description']
                                    ? $attrs['description']
                                    : ($attrs['file_name'] ? $attrs['file_name'] : $name);
                                $html .= '<a href="' . htmlspecialchars($src) . '" title="'
                                    . htmlspecialchars($attrs['description']) . '">' . $name . '</a>';
                            }
                        }
                        break;
                    case 'table':
                        if (isset($element['content']['data'])) {
                            if (!is_array($element['content']['data'])) {
                                $element['content']['data'] = @json_decode($element['content']['data'], true);
                            }
                            if (is_array($element['content']['data'])) {
                                $html .= '<table>';
                                foreach ($element['content']['data'] as $row) {
                                    $html .= '<tr>';
                                    foreach ($row as $cell) {
                                        $html .= '<td>' . $cell . '</td>';
                                    }
                                    $html .= '</tr>';
                                }
                                $html .= '<table>';
                            }
                        }
                        break;
                }
                if ($html) {
                    $prepend = '';
                    $append = '';
                    if (isset($element['options']['tag']) and $element['options']['tag']) {
                        $element['options']['tag'] = preg_replace('/[^a-z]+/', '',
                            strtolower($element['options']['tag']));
                        if ($element['options']['tag']) {
                            $prepend = '<' . $element['options']['tag'];
                            if (isset($element['options']['tag_id']) and $element['options']['tag_id']) {
                                $prepend .= ' id="' . htmlspecialchars($element['options']['tag_id']) . '"';
                            }
                            if (isset($element['options']['tag_class']) and $element['options']['tag_class']) {
                                $prepend .= ' class="' . htmlspecialchars($element['options']['tag_class']) . '"';
                            }
                            $prepend .= '>';
                        }
                    }
                    if (isset($element['options']['tag']) and $element['options']['tag']) {
                        $append = '</' . $element['options']['tag'] . '>';
                    }
                    $post_content[] = $prepend . $html . $append;
                }
            }
        }
        return implode("\n\n", $post_content);
    }

    /**
     * Reformat title.
     *
     * @param $data
     * @return string
     */
    private function _getFormattedTitle(array $data)
    {
        $title = (isset($data['contents']['page_title']) and $data['contents']['page_title'])
            ? $data['contents']['page_title']
            : (isset($data['text']) ? $data['text'] : '');
        if ($this->options['titles'] === 'ucfirst') {
            if (function_exists('mb_strtolower')) {
                $title = mb_strtolower($title);
                $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
            } else {
                $title = ucfirst(strtolower($title));
            }
        } elseif ($this->options['titles'] === 'ucwords') {
            if (function_exists('mb_convert_case')) {
                $title = mb_convert_case($title, MB_CASE_TITLE);
            } else {
                $title = ucwords(strtolower($title));
            }
        }
        return $title;
    }

    /**
     * Parse single node XML element.
     *
     * @param DOMElement $node
     * @return array|string
     */
    private function _parseSlickplanXmlNode($node)
    {
        if (isset($node->nodeType)) {
            if ($node->nodeType === XML_CDATA_SECTION_NODE or $node->nodeType === XML_TEXT_NODE) {
                return trim($node->textContent);
            } elseif ($node->nodeType === XML_ELEMENT_NODE) {
                $output = array();
                for ($i = 0, $j = $node->childNodes->length; $i < $j; ++$i) {
                    $child_node = $node->childNodes->item($i);
                    $value = $this->_parseSlickplanXmlNode($child_node);
                    if (isset($child_node->tagName)) {
                        if (!isset($output[$child_node->tagName])) {
                            $output[$child_node->tagName] = array();
                        }
                        $output[$child_node->tagName][] = $value;
                    } elseif ($value !== '') {
                        $output = $value;
                    }
                }

                if (is_array($output)) {
                    foreach ($output as $tag => $value) {
                        if (is_array($value) and count($value) === 1) {
                            $output[$tag] = $value[0];
                        }
                    }
                    if (empty($output)) {
                        $output = '';
                    }
                }

                if ($node->attributes->length) {
                    $attributes = array();
                    foreach ($node->attributes as $attr_name => $attr_node) {
                        $attributes[$attr_name] = (string)$attr_node->value;
                    }
                    if (!is_array($output)) {
                        $output = array(
                            '@value' => $output,
                        );
                    }
                    $output['@attributes'] = $attributes;
                }
                return $output;
            }
        }
        return array();
    }

    /**
     * Check if the array is from a correct Slickplan XML file.
     *
     * @param array $array
     * @param bool $parsed
     * @return bool
     */
    private function _isCorrectSlickplanXmlFile($array, $parsed = false)
    {
        $first_test = (
            $array
            and is_array($array)
            and isset($array['title'], $array['version'], $array['link'])
            and is_string($array['link']) and strstr($array['link'], 'slickplan.')
        );
        if ($first_test) {
            if ($parsed) {
                if (isset($array['sitemap']) and is_array($array['sitemap'])) {
                    return true;
                }
            } elseif (
                isset($array['section']['options']['id'], $array['section']['cells'])
                or isset($array['section'][0]['options']['id'], $array['section'][0]['cells'])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get multidimensional array, put all child pages as nested array of the parent page.
     *
     * @param array $array
     * @return array
     */
    private function _getMultidimensionalArrayHelper(array $array)
    {
        $cells = array();
        $main_section_key = -1;
        $relation_section_cell = array();
        foreach ($array['section'] as $section_key => $section) {
            if (
                isset($section['@attributes']['id'], $section['cells']['cell'])
                and is_array($section['cells']['cell'])
            ) {
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    if (isset($cell['@attributes']['id'])) {
                        $cell_id = $cell['@attributes']['id'];
                        if (isset($cell['section']) and $cell['section']) {
                            $relation_section_cell[$cell['section']] = $cell_id;
                        }
                    } else {
                        unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                    }
                }
            } else {
                unset($array['section'][$section_key]);
            }
        }
        foreach ($array['section'] as $section_key => $section) {
            $section_id = $section['@attributes']['id'];
            if ($section_id !== 'svgmainsection') {
                $remove = true;
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    $cell['level'] = (string)$cell['level'];
                    if ($cell['level'] === 'home') {
                        unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                    } elseif ($cell['level'] === '1' and isset($relation_section_cell[$section_id])) {
                        $array['section'][$section_key]['cells']['cell'][$cell_key]['parent']
                            = $relation_section_cell[$section_id];
                        $remove = false;
                        $array['section'][$section_key]['cells']['cell'][$cell_key]['order'] *= 10;
                    }
                }
                if ($remove) {
                    unset($array['section'][$section_key]);
                }
            } else {
                $main_section_key = $section_key;
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    $array['section'][$section_key]['cells']['cell'][$cell_key]['order'] /= 1000;
                }
            }
        }
        foreach ($array['section'] as $section_key => $section) {
            $section_cells = array();
            foreach ($section['cells']['cell'] as $cell_key => $cell) {
                $section_cells[] = $cell;
            }
            usort($section_cells, array($this, '_sortPages'));
            $array['section'][$section_key]['cells']['cell'] = $section_cells;
            $cells = array_merge($cells, $section_cells);
            unset($section_cells);
        }
        $multi_array = array();
        if (isset($array['section'][$main_section_key]['cells']['cell'])) {
            foreach ($array['section'][$main_section_key]['cells']['cell'] as $cell) {
                if (isset($cell['@attributes']['id']) and (
                        $cell['level'] === 'home' or $cell['level'] === 'util' or $cell['level'] === 'foot'
                        or $cell['level'] === '1' or $cell['level'] === 1
                    )
                ) {
                    $level = $cell['level'];
                    if (!isset($multi_array[$level]) or !is_array($multi_array[$level])) {
                        $multi_array[$level] = array();
                    }
                    $childs = $this->_getMultidimensionalArray($cells, $cell['@attributes']['id']);
                    $cell = array(
                        'id' => $cell['@attributes']['id'],
                        'title' => $this->_getFormattedTitle($cell),
                    );
                    if ($childs) {
                        $cell['childs'] = $childs;
                    }
                    $multi_array[$level][] = $cell;
                }
            }
        }
        unset($array, $cells, $relation_section_cell);
        return $multi_array;
    }

    /**
     * Put all child pages as nested array of the parent page.
     *
     * @param array $array
     * @param $parent
     * @param $summary
     * @return array
     */
    private function _getMultidimensionalArray(array $array, $parent)
    {
        $cells = array();
        foreach ($array as $cell) {
            if (isset($cell['parent'], $cell['@attributes']['id']) and $cell['parent'] === $parent) {
                $childs = $this->_getMultidimensionalArray($array, $cell['@attributes']['id']);
                $cell = array(
                    'id' => $cell['@attributes']['id'],
                    'title' => $this->_getFormattedTitle($cell),
                );
                if ($childs) {
                    $cell['childs'] = $childs;
                }
                $cells[] = $cell;
            }
        }
        return $cells;
    }

    /**
     * Sort cells.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    private function _sortPages(array &$a, array &$b)
    {
        if (isset($a['order'], $b['order'])) {
            return ($a['order'] < $b['order']) ? -1 : 1;
        }
        return 0;
    }

    /**
     * Replace internal links with correct pages URLs.
     *
     * @param $content
     * @param $force_parse
     * @return bool
     */
    private function _parseInternalLinks($content, $force_parse = false)
    {
        preg_match_all('/href="slickplan:([a-z0-9]+)"/isU', $content, $internal_links);
        if (isset($internal_links[1]) and is_array($internal_links[1]) and count($internal_links[1])) {
            $internal_links = array_unique($internal_links[1]);
            $links_replace = array();
            foreach ($internal_links as $cell_id) {
                if (
                    isset($this->options['imported_pages'][$cell_id])
                    and $this->options['imported_pages'][$cell_id]
                ) {
                    $links_replace['="slickplan:' . $cell_id . '"'] = '="'
                        . htmlspecialchars($this->options['imported_pages'][$cell_id]) . '"';
                } elseif ($force_parse) {
                    $links_replace['="slickplan:' . $cell_id . '"'] = '="#"';
                } else {
                    $this->_has_unparsed_internal_links = true;
                }
            }
            if (count($links_replace)) {
                return strtr($content, $links_replace);
            }
        }
        return false;
    }

    /**
     * Check if there are any pages with unparsed internal links, if yes - replace links with real URLs
     */
    private function _checkForInternalLinks()
    {
        if (isset($this->options['internal_links']) and is_array($this->options['internal_links'])) {
            foreach ($this->options['internal_links'] as $page_id) {
                $page = Page::getByID($page_id);
                foreach ($page->getBlocks() as $block) {
                    if ($block->getBlockTypeHandle() === 'content') {
                        $page_content = $block->getInstance()->getContent();
                        $updated_content = $this->_parseInternalLinks($page_content);
                        if ($updated_content) {
                            $content_data = array(
                                'content' => $updated_content,
                            );
                            $block->getInstance()->save($content_data);
                            $block->refreshCache();
                        }
                        break;
                    }
                }
            }
        }
    }

}
