<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.InsertHeadCode
 *
 * @copyright   Copyright (C) 2025 Colorpack Creations Co.,Ltd. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * @website     https://colorpack.co.th/
 * @email       office@colorpack.co.th
 * @developer   Pisan Chueachatchai
 * 
 * If you find this plugin helpful, you can support the developer at:
 * https://buymeacoffee.com/cheuachatchai
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Form\Form;

/**
 * Plugin to insert custom code into the head section and before closing body tag
 *
 * @since  1.0.0
 */
class PlgSystemInsertHeadCode extends CMSPlugin
{
    protected $app;
    protected $autoloadLanguage = true;
    
    // เพิ่มบรรทัดนี้เพื่อกำหนดรูปแบบอันตรายที่จะตรวจสอบ
    protected $dangerousPatterns = [
        '/eval\s*\(/i',                  // ฟังก์ชัน eval()
        '/base64_decode\s*\(/i',         // base64_decode
        '/create_function\s*\(/i',       // create_function
        '/include\s*\(/i',               // include, include_once
        '/require\s*\(/i',               // require, require_once
        '/assert\s*\(/i',                // assert
        '/system\s*\(/i',                // system
        '/exec\s*\(/i',                  // exec
        '/passthru\s*\(/i',              // passthru
        '/shell_exec\s*\(/i',            // shell_exec
        '/[`].*?[`]/i',                  // backtick operators
        '/preg_replace.*\/e/i',          // preg_replace with /e modifier
        '/file_(get|put)_contents\s*\(/i'// file_get_contents/file_put_contents
    ];

    // เพิ่มตัวแปรสมาชิกเพื่อเก็บ GTM ID ระหว่าง onBeforeRender และ onAfterRender
    private $gtmId = '';

    public function onAfterInitialise()
    {
        $path = JPATH_ROOT . '/images/custom-css';
        if (!is_dir($path)) {
            try {
                // ตรวจสอบสิทธิ์ก่อนสร้างโฟลเดอร์
                $user = Factory::getUser();
                if (!$user->authorise('core.admin')) {
                    return;
                }
                
                // ใช้สิทธิ์ที่เข้มงวดขึ้น
                mkdir($path, 0750, true);
                
                // สร้างไฟล์ index.html เพื่อป้องกันการเข้าถึงโดยตรง
                $indexFile = $path . '/index.html';
                if (!file_exists($indexFile)) {
                    file_put_contents($indexFile, '<html><body bgcolor="#FFFFFF"></body></html>');
                }
                
                // สร้างไฟล์ .htaccess เพื่อควบคุมการเข้าถึง
                $htaccessFile = $path . '/.htaccess';
                if (!file_exists($htaccessFile)) {
                    $htaccessContent = <<<EOT
<FilesMatch "\.css$">
    Allow from all
</FilesMatch>
<FilesMatch "^(?!.*\.css$)">
    Order deny,allow
    Deny from all
</FilesMatch>
EOT;
                    file_put_contents($htaccessFile, $htaccessContent);
                }
            } catch (\Exception $e) {
                // บันทึกข้อผิดพลาด
                $this->logSecurityWarning('Failed to create custom CSS directory: ' . $e->getMessage());
            }
        }

        // เพิ่มการซ่อมแซมโครงสร้างข้อมูลอัตโนมัติเมื่อพบปัญหา
        $headBlocks = $this->params->get('head_code_blocks');
        $needsRepair = false;
        
        if (is_object($headBlocks) || is_array($headBlocks)) {
            $blocks = is_object($headBlocks) ? (array)$headBlocks : $headBlocks;
            
            // ตรวจสอบรูปแบบที่ผิดปกติ
            foreach ($blocks as $key => $value) {
                if (is_string($key) && strpos($key, 'head_code_blocks') === 0) {
                    $needsRepair = true;
                    break;
                }
            }
        }
        
        if ($needsRepair && !$this->app->isClient('administrator')) {
            // ซ่อมแซมโครงสร้างข้อมูลสำหรับการแสดงผล (ไม่บันทึกลงฐานข้อมูล)
            $repairedBlocks = [];
            $this->deepRepairHeadCodeBlocks($headBlocks, $repairedBlocks);
            
            // สร้าง Registry ใหม่ด้วยค่าที่แก้ไขแล้ว
            $newParams = clone $this->params;
            $newParams->set('head_code_blocks', $repairedBlocks);
            
            // แทนที่ params เดิมด้วยค่าที่แก้ไขแล้ว (สำหรับการแสดงผลเท่านั้น)
            $reflection = new \ReflectionClass($this);
            $property = $reflection->getProperty('params');
            $property->setAccessible(true);
            $property->setValue($this, $newParams);
        }
    }

    public function onBeforeRender()
    {
        // เฉพาะในส่วน frontend เท่านั้น
        if ($this->app->isClient('administrator') || $this->app->isClient('api') || $this->app->isClient('cli')) {
            return;
        }
        
        $document = Factory::getDocument();
        
        // เพิ่มโค้ดส่วนหัวก่อน 
        $this->processHeadBlocks($document);
        
        // เพิ่มแท็ก Analytics หลังจากเพิ่มโค้ดส่วนหัว
        $this->processAnalyticsTags($document);
        
        // เพิ่ม CSS 
        $this->processCssBlocks($document);
    }

    // แก้ฟังก์ชัน onAfterRender()
    public function onAfterRender()
    {
        // เฉพาะในส่วน frontend เท่านั้น
        if ($this->app->isClient('administrator') || $this->app->isClient('api') || $this->app->isClient('cli')) {
            return;
        }
        
        $app = Factory::getApplication();
        $body = $app->getBody();
        
        // ถ้ามี GTM ID ให้เพิ่ม GTM noscript ที่ต้นของ body
        if (!empty($this->gtmId)) {
            $gtmBodyCode = "<!-- Google Tag Manager (noscript) -->
<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=" . $this->gtmId . "\"
height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->";
            
            // ถ้าเจอ <body> ให้แทรกโค้ดหลังแท็กเปิด
            if (strpos($body, '<body') !== false) {
                $pattern = '/<body[^>]*>/i';
                $replacement = '$0' . $gtmBodyCode;
                $body = preg_replace($pattern, $replacement, $body, 1);
                $this->logSecurityWarning("Added Google Tag Manager body code");
            }
        }
        
        // เปลี่ยนจากการเรียกฟังก์ชัน processBodyBlocks() เปล่าๆ
        // เป็นการใส่โค้ดการทำงานโดยตรงในฟังก์ชันนี้
        
        // ประมวลผลบล็อกโค้ดส่วน body
        $bodyBlocks = $this->params->get('body_code_blocks', array());
        
        // ตรวจสอบเนื้อหาของ body
        $this->logSecurityWarning("Body content length: " . strlen($body));
        $this->logSecurityWarning("Body contains </body>: " . (strpos($body, '</body>') !== false ? 'Yes' : 'No'));
        
        // ลองใช้วิธีค้นหา body blocks ด้วยวิธีเดียวกับ head blocks
        $repairedBodyBlocks = [];
        $this->deepRepairBodyCodeBlocks($bodyBlocks, $repairedBodyBlocks);
        
        $this->logSecurityWarning("Found " . count($repairedBodyBlocks) . " body blocks after deep repair");
        
        // ใช้ $repairedBodyBlocks แทน $processedBodyBlocks
        if (empty($repairedBodyBlocks)) {
            $this->logSecurityWarning("No body blocks found");
            // ไม่ return ที่นี่ เพราะเราต้องทำการ setBody ไม่ว่าจะมีบล็อกหรือไม่
        } else {
            // สร้างโค้ดสำหรับแทรกก่อน </body>
            $bodyInjectedCode = '';
            
            // ประมวลผล Body Blocks
            foreach ($repairedBodyBlocks as $block) {
                $label = isset($block['label']) ? htmlspecialchars(trim($block['label'])) : '';
                $code = isset($block['code']) ? trim($block['code']) : '';
                
                if ($code !== '') {
                    // ตรวจสอบรูปแบบอันตราย
                    $isSafe = true;
                    foreach ($this->dangerousPatterns as $pattern) {
                        if (preg_match($pattern, $code)) {
                            $this->logSecurityWarning('Potentially dangerous code detected: ' . substr($code, 0, 100) . '...');
                            $isSafe = false;
                            break;
                        }
                    }
                    
                    if ($isSafe) {
                        $comment = $this->getBilingualComment($label, 'body');
                        $bodyInjectedCode .= $comment . $code . "\n";
                        
                        $this->logSecurityWarning("Added body code block: " . substr($code, 0, 30) . "...");
                    }
                }
            }
            
            // ตรวจสอบว่ามีโค้ดที่จะแทรกหรือไม่
            if (!empty($bodyInjectedCode)) {
                if (strpos($body, '</body>') !== false) {
                    // แทรกโค้ดก่อนปิด body tag
                    $body = str_ireplace('</body>', $bodyInjectedCode . '</body>', $body);
                    $this->logSecurityWarning("Successfully injected " . strlen($bodyInjectedCode) . " characters before </body>");
                } else {
                    $this->logSecurityWarning("Could not find </body> tag in the page");
                    
                    // ถ้าไม่พบ </body> ให้ลองแทรกที่ท้ายเอกสาร
                    $body .= "\n" . $bodyInjectedCode;
                    $body .= "\n<!-- Insert Head Code: Added at end of document -->\n";
                    $this->logSecurityWarning("Injected code at the end of document instead");
                }
            }
        }
        
        // ต้อง set body ใหม่หลังจากการปรับเปลี่ยน
        $app->setBody($body);
    }

    // ลบหรือคอมเม้นทิ้งฟังก์ชัน processBodyBlocks() เนื่องจากนำโค้ดไปใช้โดยตรงใน onAfterRender() แล้ว
    /*
    private function processBodyBlocks()
    {
        // ... โค้ดเดิม ...
    }
    */

    /**
     * ซ่อมแซม body_code_blocks เช่นเดียวกับ head_code_blocks
     */
    private function deepRepairBodyCodeBlocks($data, &$result, $depth = 0)
    {
        // ป้องกันการวนซ้ำมากเกินไป
        if ($depth > 5) {
            return;
        }
        
        // บันทึก log สำหรับการดีบัก
        $type = gettype($data);
        $count = is_array($data) || is_object($data) ? count((array)$data) : 0;
        $this->logSecurityWarning("deepRepairBodyCodeBlocks: depth=$depth, type=$type, count=$count");
        
        // กรณีเป็น object
        if (is_object($data)) {
            $data = (array) $data;
        }
        
        // กรณีเป็น array
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // ตรวจสอบรูปแบบโดยตรง label + code
                if ((is_string($key) && $key === 'label') || (is_string($key) && $key === 'code')) {
                    // พบโครงสร้างที่น่าจะถูกต้อง - ตรวจสอบว่ามี code ด้วยหรือไม่
                    $parent = $data;
                    if (isset($parent['label']) && isset($parent['code'])) {
                        // เพิ่มเข้าในผลลัพธ์ - แต่ตรวจสอบว่าไม่ซ้ำกับที่มีอยู่แล้ว
                        $isDuplicate = false;
                        foreach ($result as $existingBlock) {
                            if ($existingBlock['label'] === $parent['label'] && 
                                $existingBlock['code'] === $parent['code']) {
                                $isDuplicate = true;
                                break;
                            }
                        }
                        
                        if (!$isDuplicate) {
                            $result[] = [
                                'label' => $parent['label'],
                                'code' => $parent['code']
                            ];
                            $this->logSecurityWarning("Found valid body block: " . $parent['label']);
                        } else {
                            $this->logSecurityWarning("Skipping duplicate body block: " . $parent['label']);
                        }
                        
                        // หยุดการทำงานในระดับนี้เพราะเจอข้อมูลที่ต้องการแล้ว
                        return;
                    }
                }
                
                // ตรวจสอบรูปแบบ "params"
                if (is_string($key) && $key === 'params') {
                    if (is_array($value) || is_object($value)) {
                        $subValue = is_object($value) ? (array) $value : $value;
                        
                        // กรณีพบ label และ code ใน params
                        if (isset($subValue['label']) && isset($subValue['code'])) {
                            // ตรวจสอบว่าไม่ซ้ำกับที่มีอยู่แล้ว
                            $isDuplicate = false;
                            foreach ($result as $existingBlock) {
                                if ($existingBlock['label'] === $subValue['label'] && 
                                    $existingBlock['code'] === $subValue['code']) {
                                    $isDuplicate = true;
                                    break;
                                }
                            }
                            
                            if (!$isDuplicate) {
                                $result[] = [
                                    'label' => $subValue['label'],
                                    'code' => $subValue['code']
                                ];
                                $this->logSecurityWarning("Found valid body block in params: " . $subValue['label']);
                            } else {
                                $this->logSecurityWarning("Skipping duplicate body block in params: " . $subValue['label']);
                            }
                        }
                        
                        // ค้นหาเชิงลึกต่อใน params
                        $this->deepRepairBodyCodeBlocks($subValue, $result, $depth + 1);
                    }
                }
                
                // รูปแบบ "body_code_blocks" ซ้อนใน params
                if (is_string($key) && strpos($key, 'body_code_blocks') === 0) {
                    if (is_array($value) || is_object($value)) {
                        $this->deepRepairBodyCodeBlocks($value, $result, $depth + 1);
                    }
                }
                
                // รูปแบบอื่นๆ ที่อาจเป็น array หรือ object ซ้อนลึกลงไป
                if (is_array($value) || is_object($value)) {
                    $this->deepRepairBodyCodeBlocks($value, $result, $depth + 1);
                }
            }
        }
    }

    public function onContentPrepareForm(Form $form, $data)
    {
        // ตรวจสอบสิทธิ์ผู้ใช้
        $user = Factory::getUser();
        if (!$user->authorise('core.admin')) {
            return; // ใช้ return เฉยๆ ไม่ต้องส่งค่า true/false
        }
        
        // เพิ่มการตรวจสอบว่า $data เป็น null หรือไม่
        if ($data === null) {
            return;
        }

        // Only modify our plugin's form
        $formName = $form->getName();
        if ($formName !== 'com_plugins.plugin') {
            return; // ใช้ return เฉยๆ ไม่ต้องส่งค่า true/false
        }
        
        // Make sure it's our plugin being edited
        $isOurPlugin = false;
        if (is_array($data) && isset($data['element']) && $data['element'] === 'insertheadcode') {
            $isOurPlugin = true;
        } elseif (is_object($data) && isset($data->element) && $data->element === 'insertheadcode') {
            $isOurPlugin = true;
        }
        
        if (!$isOurPlugin) {
            return; // ใช้ return เฉยๆ ไม่ต้องส่งค่า true/false
        }
        
        // เพิ่มการตรวจสอบเพื่อป้องกันข้อผิดพลาดเมื่อค่า params อาจเป็น null
        $params = null;
        if (is_array($data) && isset($data['params'])) {
            $params = $data['params'];
        } elseif (is_object($data) && isset($data->params)) {
            $params = $data->params;
        }
        // เพิ่มการตรวจสอบว่า $params เป็น null หรือไม่
        if ($params === null) {
            return;
        }

        // เพิ่มบรรทัดนี้เพื่อตรวจสอบโครงสร้างฟอร์ม
        $this->checkFormStructure($form);

        $cssPath = JPATH_ROOT . '/images/custom-css';
        if (!is_dir($cssPath)) {
            return;
        }

        $files = glob($cssPath . '/*.css');
        $allOptions = []; // ตัวเลือกทั้งหมดที่มี

        foreach ($files as $file) {
            $fileName = basename($file);
            if (preg_match('/^[a-zA-Z0-9._-]+\.css$/', $fileName)) {
                $allOptions[$fileName] = HTMLHelper::_('select.option', $fileName, $fileName);
            }
        }
        
        // ถ้าไม่มี CSS ให้เลือก ไม่ต้องทำอะไรต่อ
        if (empty($allOptions)) {
            return;
        }
        
        // รายการไฟล์ CSS ที่ถูกเลือกแล้ว
        $selectedFiles = [];
        
        // ถ้ามีการเลือกโหมด select และมีการเลือกไฟล์แล้ว
        if (isset($params['load_custom_css']) && $params['load_custom_css'] === 'select' && 
            isset($params['custom_css_blocks']) && is_array($params['custom_css_blocks'])) {
            
            // เก็บรายการไฟล์ที่ถูกเลือกแล้ว
            foreach ($params['custom_css_blocks'] as $block) {
                if (is_array($block) && isset($block['filename']) && !empty($block['filename'])) {
                    $selectedFiles[] = $block['filename'];
                } elseif (is_object($block) && isset($block->filename) && !empty($block->filename)) {
                    $selectedFiles[] = $block->filename;
                }
            }
        }
        
        // Apply options to the form fields in the subform
        $xml = $form->getXml();
        $fields = $xml->xpath('//field[@name="custom_css_blocks"]//field[@name="filename"]');
        
        if (!empty($fields)) {
            // เก็บค่าที่เลือกไว้จาก params
            $selectedOptions = [];
            if (isset($params['custom_css_blocks']) && is_array($params['custom_css_blocks'])) {
                foreach ($params['custom_css_blocks'] as $idx => $block) {
                    if (isset($block['filename']) && !empty($block['filename'])) {
                        $selectedOptions[$idx] = $block['filename'];
                    }
                }
            }
            
            foreach ($fields as $index => $field) {
                // ลบตัวเลือกเดิมทั้งหมด (ถ้ามี)
                $existingOptions = $field->xpath('option');
                foreach ($existingOptions as $option) {
                    unset($option[0]);
                }
                
                // เพิ่ม empty option ที่ต้นรายการ
                $emptyOption = HTMLHelper::_('select.option', '', '- เลือกไฟล์ CSS -');
                $optionElement = $field->addChild('option', $emptyOption->text);
                $optionElement->addAttribute('value', $emptyOption->value);
                
                // เพิ่มตัวเลือกทุกไฟล์ CSS
                foreach ($allOptions as $fileName => $option) {
                    $optionElement = $field->addChild('option', $option->text);
                    $optionElement->addAttribute('value', $option->value);
                    
                    // ค้นหารหัสแถวจากชื่อฟิลด์ - แก้ไขบรรทัดนี้
                    // $rowId = $this->getRowIdFromFieldPath($field);
                    
                    // ใช้ index แทน
                    $rowId = $index;
                    
                    // ทำเครื่องหมายตัวเลือกที่เลือกอยู่แล้ว
                    if (isset($selectedOptions[$rowId]) && $selectedOptions[$rowId] === $option->value) {
                        $optionElement->addAttribute('selected', 'selected');
                    }
                }
            }
            
            // โหลด XML ที่ปรับปรุงแล้วกลับเข้าฟอร์ม
            $form->load($xml->asXML());
        }

        // เพิ่มโค้ด JavaScript สำหรับปรับความกว้างของฟอร์ม
        if ($isOurPlugin) {
            $doc = Factory::getApplication()->getDocument();
            $js = <<<JS
jQuery(document).ready(function($) {
    // ปรับความกว้างของคอนเทนเนอร์หลัก
    $('.container-main').css('max-width', '95%');
    
    // ปรับความกว้างของช่องข้อความ
    $('.subform-repeatable-group textarea').css('width', '100%');
    
    // เพิ่มคลาสสำหรับสไตล์หน้ากว้าง
    $('body').addClass('plg-insertheadcode-edit');
    
    // เพิ่มปุ่มเต็มหน้าจอสำหรับ textarea
    $('.subform-repeatable-group textarea').each(function() {
        var \$textarea = $(this);
        var \$container = \$textarea.closest('.control-group');
        
        // สร้างปุ่มเต็มหน้าจอ
        var \$fullscreenBtn = $('<button type="button" class="btn btn-small float-end"><i class="icon-expand"></i> เต็มหน้าจอ</button>');
        \$container.find('.control-label').append(\$fullscreenBtn);
        
        // ปรับแก้โค้ดที่เกิดข้อผิดพลาด
        \$fullscreenBtn.on('click', function(e) {
            e.preventDefault();
            
            // สร้าง modal แบบเต็มหน้าจอ
            var modalContent = '' +
                '<div class="modal fade" id="code-fullscreen-modal" tabindex="-1" role="dialog" aria-labelledby="fullscreenModalLabel" aria-hidden="true">' +
                '   <div class="modal-dialog modal-fullscreen" role="document">' +
                '       <div class="modal-content">' +
                '           <div class="modal-header">' +
                '               <h5 class="modal-title" id="fullscreenModalLabel">แก้ไขโค้ดแบบเต็มหน้าจอ</h5>' +
                '               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                '           </div>' +
                '           <div class="modal-body">' +
                '               <textarea id="fullscreen-editor" style="width:100%; height:80vh;"></textarea>' +
                '           </div>' +
                '           <div class="modal-footer">' +
                '               <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>' +
                '               <button type="button" class="btn btn-primary" id="save-fullscreen">บันทึก</button>' +
                '           </div>' +
                '       </div>' +
                '   </div>' +
                '</div>';
            
            // เพิ่ม modal เข้าไปใน body
            $('body').append(modalContent);
            
            // โหลดค่าจาก textarea เดิม
            var originalValue = \$textarea.val();
            $('#fullscreen-editor').val(originalValue);
            
            // แสดง modal
            var modal = new bootstrap.Modal(document.getElementById('code-fullscreen-modal'));
            modal.show();
            
            // อีเวนต์เมื่อกดปุ่มบันทึก
            $('#save-fullscreen').on('click', function() {
                var newValue = $('#fullscreen-editor').val();
                \$textarea.val(newValue);
                modal.hide();
            });
            
            // ลบ modal เมื่อปิด
            $('#code-fullscreen-modal').on('hidden.bs.modal', function() {
                $(this).remove();
            });
        });
    });
});
JS;
            $doc->addScriptDeclaration($js);
        }
    }

    /**
     * ตรวจสอบโครงสร้างฟอร์ม
     */
    private function checkFormStructure($form)
    {
        // ตรวจสอบโครงสร้างฟอร์ม
        $xml = $form->getXml();
        $fields = $xml->xpath('//field[@name="custom_css_blocks"]//field[@name="filename"]');
        
        if (empty($fields)) {
            $this->logSecurityWarning("Form structure is incorrect: custom_css_blocks or filename field not found");
        } else {
            $this->logSecurityWarning("Form structure is correct: custom_css_blocks and filename field found");
        }
    }

    /**
     * บันทึก log การแจ้งเตือนด้านความปลอดภัย
     */
    private function logSecurityWarning($message)
    {
        $logDir = JPATH_ROOT . '/logs';
        $logFile = $logDir . '/security_warnings.log';
        
        // สร้างโฟลเดอร์ logs ถ้ายังไม่มี
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        
        // ใช้ try-catch เพื่อป้องกันข้อผิดพลาดที่อาจเกิดขึ้น
        try {
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        } catch (\Exception $e) {
            // ไม่ต้องทำอะไรเมื่อไม่สามารถเขียนล็อกได้
        }
    }

    /**
     * สร้างคอมเมนต์สองภาษา
     */
    private function getBilingualComment($label, $type)
    {
        $comment = '';
        if ($label !== '') {
            $comment = "\n<!-- " . htmlspecialchars($label) . " -->\n";
        }
        return $comment;
    }

    /**
     * ประมวลผล CSS Blocks
     */
    private function processCssBlocks($document)
    {
        if (!($document instanceof HtmlDocument)) {
            return;
        }
        
        $cssPath = JPATH_ROOT . '/images/custom-css';
        $cssUri = Uri::root(true) . '/images/custom-css/';
        $loadCssMode = $this->params->get('load_custom_css', 'auto');
        
        $this->logSecurityWarning("CSS mode: " . $loadCssMode);
        
        // ตรวจสอบการมีอยู่ของไดเร็กทอรี
        if (!is_dir($cssPath)) {
            if (!$this->ensureCustomCssDirectory()) {
                return;
            }
        }
        
        // ถ้าเลือกโหมด 'auto' หรือ 'all' ให้โหลดไฟล์ CSS ทั้งหมด
        if ($loadCssMode === 'auto' || $loadCssMode === 'all') {
            $cssFiles = glob($cssPath . '/*.css');
            foreach ($cssFiles as $cssFile) {
                $fileName = basename($cssFile);
                if ($fileName !== 'index.css' && preg_match('/^[a-zA-Z0-9._-]+\.css$/', $fileName)) {
                    // เพิ่ม timestamp เพื่อป้องกันการแคช
                    $timestamp = filemtime($cssFile);
                    $fileUri = $cssUri . $fileName . '?t=' . $timestamp;
                    
                    // เพิ่มคอมเมนต์พร้อมกับลิงก์ CSS โดยใช้ addCustomTag
                    $comment = $this->getBilingualComment($fileName, 'css');
                    $cssLink = '<link href="' . $fileUri . '" rel="stylesheet">';
                    $document->addCustomTag($comment . $cssLink);
                    
                    $this->logSecurityWarning("Added CSS file: " . $fileName);
                }
            }
        } 
        // ถ้าเลือกโหมด 'select' ให้โหลดเฉพาะไฟล์ที่เลือก
        elseif ($loadCssMode === 'select') {
            $customCssBlocks = $this->params->get('custom_css_blocks');
            
            // บันทึกประเภทของข้อมูลเพื่อการดีบัก
            $this->logSecurityWarning("Custom CSS blocks type: " . gettype($customCssBlocks));
            
            // แปลงเป็น array ไม่ว่าจะเป็น object หรือ array
            $blocks = [];
            
            if (is_object($customCssBlocks)) {
                // แปลง stdClass เป็น array
                $blocks = get_object_vars($customCssBlocks);
            } elseif (is_array($customCssBlocks)) {
                $blocks = $customCssBlocks;
            }
            
            $this->logSecurityWarning("Number of CSS blocks: " . count($blocks));
            
            foreach ($blocks as $block) {
                // แปลง stdClass เป็น array ถ้าจำเป็น
                if (is_object($block)) {
                    $block = get_object_vars($block);
                }
                
                $label = isset($block['label']) ? trim($block['label']) : '';
                $filename = isset($block['filename']) ? trim($block['filename']) : '';
                
                if ($filename !== '') {
                    $cssFile = $cssPath . '/' . $filename;
                    if (file_exists($cssFile)) {
                        // เพิ่ม timestamp เพื่อป้องกันการแคช
                        $timestamp = filemtime($cssFile);
                        $fileUri = $cssUri . $filename . '?t=' . $timestamp;
                        
                        // เพิ่มคอมเมนต์พร้อมกับลิงก์ CSS โดยใช้ addCustomTag
                        $commentText = !empty($label) ? $label : $filename;
                        $comment = $this->getBilingualComment($commentText, 'css');
                        $cssLink = '<link href="' . $fileUri . '" rel="stylesheet">';
                        $document->addCustomTag($comment . $cssLink);
                        
                        $this->logSecurityWarning("Added selected CSS file: " . $filename);
                    } else {
                        $this->logSecurityWarning("CSS file not found: " . $filename);
                    }
                }
            }
        }
    }

    /**
     * ตรวจสอบและสร้างไดเร็กทอรี custom-css ถ้าจำเป็น
     */
    private function ensureCustomCssDirectory()
    {
        $cssPath = JPATH_ROOT . '/images/custom-css';
        
        if (!is_dir($cssPath)) {
            try {
                // สร้างไดเร็กทอรี
                mkdir($cssPath, 0755, true);
                
                // สร้างไฟล์ index.html เพื่อป้องกันการเข้าถึงโดยตรง
                $indexFile = $cssPath . '/index.html';
                file_put_contents($indexFile, '<html><body bgcolor="#FFFFFF"></body></html>');
                
                // สร้างไฟล์ตัวอย่าง CSS
                $sampleFile = $cssPath . '/sample.css';
                $sampleContent = "/* Sample CSS file */\n";
                $sampleContent .= "body {\n  /* Add your custom styles here */\n}\n";
                file_put_contents($sampleFile, $sampleContent);
                
                $this->logSecurityWarning("Created custom CSS directory and sample files");
                return true;
            } catch (\Exception $e) {
                $this->logSecurityWarning("Failed to create custom CSS directory: " . $e->getMessage());
                return false;
            }
        }
        
        return true;
    }

    /**
     * ประมวลผล Head Blocks
     */
    private function processHeadBlocks($document)
    {
        $headBlocks = $this->params->get('head_code_blocks', array());
        if (empty($headBlocks)) {
            return;
        }

        foreach ($headBlocks as $block) {
            $label = isset($block['label']) ? htmlspecialchars(trim($block['label'])) : '';
            $code = isset($block['code']) ? trim($block['code']) : '';

            if ($code !== '') {
                // ตรวจสอบรูปแบบอันตราย
                $isSafe = true;
                foreach ($this->dangerousPatterns as $pattern) {
                    if (preg_match($pattern, $code)) {
                        $this->logSecurityWarning('Potentially dangerous code detected: ' . substr($code, 0, 100) . '...');
                        $isSafe = false;
                        break;
                    }
                }

                if ($isSafe) {
                    $comment = $this->getBilingualComment($label, 'head');
                    $document->addCustomTag($comment . $code);
                }
            }
        }
    }

    /**
     * ซ่อมแซม head_code_blocks
     */
    private function deepRepairHeadCodeBlocks($data, &$result, $depth = 0)
    {
        // ป้องกันการวนซ้ำมากเกินไป
        if ($depth > 5) {
            return;
        }

        // บันทึก log สำหรับการดีบัก
        $type = gettype($data);
        $count = is_array($data) || is_object($data) ? count((array)$data) : 0;
        $this->logSecurityWarning("deepRepairHeadCodeBlocks: depth=$depth, type=$type, count=$count");

        // กรณีเป็น object
        if (is_object($data)) {
            $data = (array) $data;
        }

        // กรณีเป็น array
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // ตรวจสอบรูปแบบโดยตรง label + code
                if ((is_string($key) && $key === 'label') || (is_string($key) && $key === 'code')) {
                    // พบโครงสร้างที่น่าจะถูกต้อง - ตรวจสอบว่ามี code ด้วยหรือไม่
                    $parent = $data;
                    if (isset($parent['label']) && isset($parent['code'])) {
                        // เพิ่มเข้าในผลลัพธ์ - แต่ตรวจสอบว่าไม่ซ้ำกับที่มีอยู่แล้ว
                        $isDuplicate = false;
                        foreach ($result as $existingBlock) {
                            if ($existingBlock['label'] === $parent['label'] &&
                                $existingBlock['code'] === $parent['code']) {
                                $isDuplicate = true;
                                break;
                            }
                        }

                        if (!$isDuplicate) {
                            $result[] = [
                                'label' => $parent['label'],
                                'code' => $parent['code']
                            ];
                            $this->logSecurityWarning("Found valid head block: " . $parent['label']);
                        } else {
                            $this->logSecurityWarning("Skipping duplicate head block: " . $parent['label']);
                        }

                        // หยุดการทำงานในระดับนี้เพราะเจอข้อมูลที่ต้องการแล้ว
                        return;
                    }
                }

                // ตรวจสอบรูปแบบ "params"
                if (is_string($key) && $key === 'params') {
                    if (is_array($value) || is_object($value)) {
                        $subValue = is_object($value) ? (array) $value : $value;

                        // กรณีพบ label และ code ใน params
                        if (isset($subValue['label']) && isset($subValue['code'])) {
                            // ตรวจสอบว่าไม่ซ้ำกับที่มีอยู่แล้ว
                            $isDuplicate = false;
                            foreach ($result as $existingBlock) {
                                if ($existingBlock['label'] === $subValue['label'] &&
                                    $existingBlock['code'] === $subValue['code']) {
                                    $isDuplicate = true;
                                    break;
                                }
                            }

                            if (!$isDuplicate) {
                                $result[] = [
                                    'label' => $subValue['label'],
                                    'code' => $subValue['code']
                                ];
                                $this->logSecurityWarning("Found valid head block in params: " . $subValue['label']);
                            } else {
                                $this->logSecurityWarning("Skipping duplicate head block in params: " . $subValue['label']);
                            }
                        }

                        // ค้นหาเชิงลึกต่อใน params
                        $this->deepRepairHeadCodeBlocks($subValue, $result, $depth + 1);
                    }
                }

                // รูปแบบ "head_code_blocks" ซ้อนใน params
                if (is_string($key) && strpos($key, 'head_code_blocks') === 0) {
                    if (is_array($value) || is_object($value)) {
                        $this->deepRepairHeadCodeBlocks($value, $result, $depth + 1);
                    }
                }

                // รูปแบบอื่นๆ ที่อาจเป็น array หรือ object ซ้อนลึกลงไป
                if (is_array($value) || is_object($value)) {
                    $this->deepRepairHeadCodeBlocks($value, $result, $depth + 1);
                }
            }
        }
    }

    /**
     * ประมวลผล Analytics แท็ก
     */
    private function processAnalyticsTags($document)
    {
        if (!($document instanceof HtmlDocument)) {
            return;
        }
        
        // Google Tag Manager (ส่วนหัว)
        $gtmId = $this->params->get('gtm_id', '');
        if (!empty($gtmId)) {
            $gtmHeadCode = "<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','" . $gtmId . "');</script>
<!-- End Google Tag Manager -->";
            
            $document->addCustomTag($gtmHeadCode);
            $this->logSecurityWarning("Added Google Tag Manager head code");
            
            // เก็บ GTM ID ไว้สำหรับใช้ในส่วน body
            $this->gtmId = $gtmId;
        }
        
        // Google Analytics 4
        $ga4Id = $this->params->get('ga4_id', '');
        if (!empty($ga4Id)) {
            $ga4Code = "<!-- Google Analytics 4 -->
<script async src=\"https://www.googletagmanager.com/gtag/js?id=" . $ga4Id . "\"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '" . $ga4Id . "');
</script>
<!-- End Google Analytics 4 -->";
            
            $document->addCustomTag($ga4Code);
            $this->logSecurityWarning("Added Google Analytics 4 code");
        }
        
        // Facebook Pixel
        $fbPixelId = $this->params->get('fb_pixel_id', '');
        if (!empty($fbPixelId)) {
            $fbPixelCode = "<!-- Facebook Pixel Code -->
<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '" . $fbPixelId . "');
  fbq('track', 'PageView');
</script>
<noscript><img height=\"1\" width=\"1\" style=\"display:none\"
  src=\"https://www.facebook.com/tr?id=" . $fbPixelId . "&ev=PageView&noscript=1\"
/></noscript>
<!-- End Facebook Pixel Code -->";
            
            $document->addCustomTag($fbPixelCode);
            $this->logSecurityWarning("Added Facebook Pixel code");
        }
        
        // LINE Tag
        $lineTagId = $this->params->get('line_tag_id', '');
        if (!empty($lineTagId)) {
            $lineTagCode = "<!-- LINE Tag Base Code -->
<script>
  !function(e,t,n,c,a){e.ltq=e.ltq||[],e.ltq.push(a),c=t.getElementsByTagName(n)[0],
  a=t.createElement(n),a.async=!0,a.src='https://tag.line-apps.com/ltag.js',
  c.parentNode.insertBefore(a,c)}(window,document,'script',{},'LT" . $lineTagId . "');
</script>
<noscript>
  <img height=\"1\" width=\"1\" style=\"display:none\" src=\"https://tr.line.me/tag.gif?c_t=lap&t_id=" . $lineTagId . "&e=pv&noscript=1\">
</noscript>
<!-- End LINE Tag Base Code -->";
            
            $document->addCustomTag($lineTagCode);
            $this->logSecurityWarning("Added LINE Tag code");
        }
    }
}
?>
