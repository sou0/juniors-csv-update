<?php
$json = '[{"id":"520b977","elType":"widget","widgetType":"heading","settings":{"title":"Como funciona a Contato Seguro?","header_size":""},"elements":[]}, {"id":"123","elType":"widget","widgetType":"premium-dual-header","settings":{"first_text":"para o seu ","second_text":"negócio"},"elements":[]}]';
$data = json_decode($json, true);

$targets = ['h1', 'h2', 'p'];
$replacements = [
    ['old' => 'Como', 'new' => 'COMO'],
    ['old' => 'negócio', 'new' => 'empresa'],
    ['old' => 'como', 'new' => 'comooo']
];

$el_updated = false;
$traverse = function(&$elements) use (&$traverse, &$el_updated, $replacements, $targets) {
    if (!is_array($elements)) return;
    foreach ($elements as &$el) {
        if (isset($el['elType']) && $el['elType'] === 'widget') {
            $is_standard = false;
            if ($el['widgetType'] === 'heading') {
                $size = isset($el['settings']['header_size']) ? strtolower($el['settings']['header_size']) : 'h2';
                if (empty($size)) $size = 'h2';
                if (in_array($size, $targets)) {
                    if (isset($el['settings']['title'])) {
                        $old_t = $el['settings']['title'];
                        foreach ($replacements as $rep) { $el['settings']['title'] = str_replace($rep['old'], $rep['new'], $el['settings']['title']); }
                        if ($el['settings']['title'] !== $old_t) $el_updated = true;
                        $is_standard = true;
                    }
                }
            } elseif ($el['widgetType'] === 'text-editor' && in_array('p', $targets)) {
                if (isset($el['settings']['editor'])) {
                    $old_e = $el['settings']['editor'];
                    foreach ($replacements as $rep) { $el['settings']['editor'] = str_replace($rep['old'], $rep['new'], $el['settings']['editor']); }
                    if ($el['settings']['editor'] !== $old_e) $el_updated = true;
                    $is_standard = true;
                }
            }

            // Fallback for Custom Widgets (like premium-dual-header)
            if (!$is_standard && isset($el['settings']) && is_array($el['settings'])) {
                $replace_recursive = function(&$array) use (&$replace_recursive, &$el_updated, $replacements) {
                    foreach ($array as $key => &$value) {
                        if (is_string($value)) {
                            $old_v = $value;
                            foreach ($replacements as $rep) { $value = str_replace($rep['old'], $rep['new'], $value); }
                            if ($value !== $old_v) $el_updated = true;
                        } elseif (is_array($value)) {
                            $replace_recursive($value);
                        }
                    }
                };
                $replace_recursive($el['settings']);
            }
        }
        if (isset($el['elements'])) $traverse($el['elements']);
    }
};

$traverse($data);
print_r($data);
echo "Updated: " . ($el_updated ? 'true' : 'false') . "\n";
