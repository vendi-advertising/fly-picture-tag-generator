<?php

namespace Vendi\ImageUtilities\FlyPictureTagGenerator;

class PictureTagUtility
{
    private static $instance = null;

    private $all_image_sizes = null;

    public static function get_instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    protected function does_image_size_exist($size): bool
    {
        assert(function_exists('fly_get_all_image_sizes'));

        if (!is_string($size)) {
            return false;
        }

        if (null === $this->all_image_sizes) {
            $this->all_image_sizes = fly_get_all_image_sizes();
        }

        return array_key_exists($size, $this->all_image_sizes);
    }

    protected function supports_webp(): bool
    {
        return class_exists('WebPConvert\\WebPConvert');
    }

    public function get_attachment_image_src($attachment_id = 0, $size = '', $crop = null): array
    {
        assert(function_exists('fly_get_attachment_image_src'));
        return fly_get_attachment_image_src($attachment_id, $size, $crop);
    }

    public function get_attachment_image_html($attachment_id = 0, $size = '', $crop = null, $attr = []): string
    {
        assert(function_exists('fly_get_attachment_image'));
        return fly_get_attachment_image($attachment_id, $size, $crop, $attr);;
    }

    protected function create_picture_tag(array $image_parts, array $root_css_classes = []): string
    {
        $ret = [];
        $ret[] = sprintf('<picture class="%1$s">', esc_attr(implode(' ', $root_css_classes)));
        $last = null;
        foreach ($image_parts as $image) {
            if (is_string($image)) {
                $last = $image;
                continue;
            }

            if (is_array($image)) {
                $html = '<source ';
                foreach ($image as $name => $value) {
                    $html .= " $name=" . '"' . esc_attr($value) . '"';
                }
                $html .= ' />';
                $ret[] = $html;
            }
        }

        if ($last) {
            $ret[] = $last;
        }

        $ret[] = '</picture>';

        return implode('', $ret);
    }

    public function sanitize_picture_tag(string $html): string
    {
        return wp_kses(
            $html,
            [
                'picture' => [
                    'class' => [],
                ],
                'img' => [
                    'alt' => [],
                    'title' => [],
                    'width' => [],
                    'height' => [],
                    'src' => [],
                ],
                'source' => [
                    'srcset' => [],
                    'media' => [],
                    'type' => [],
                    'src' => [],
                ],
            ]
        );
    }

    public function get_attachment_picture($attachment_id = 0, $size = '', $crop = null, $attr = []): string
    {
        // This will hold all possible image versions. If the value is a string it is used literally, otherwise
        // it should be an array of possible attributes for the <source /> tag.
        $images = [];

        // We only want classes to be on the <picture> tag, so if any are passed, store those
        $classes = $attr['class'] ?? [];

        // Let Fly do the work of creating the <img /> tag. This is duplicated work but it is cached.
        $original_image_html = $this->get_attachment_image_html($attachment_id, $size, $crop, $attr);
        if (!$original_image_html) {
            return '';
        }

        // We don't support manual width/height here
        if (!$size || !is_string($size)) {
            return $original_image_html;
        }

        // See if the image exists in the first place, and if not, fail early
        $original_image = $this->get_attachment_image_src($attachment_id, $size);
        if (!$original_image || !is_array($original_image)) {
            return '';
        }

        // If a class was created on the <img /> tag, take it off and append to the ones that
        // will be used on the <picture /> tag
        if (preg_match('/(?<entire>class="(?<class>[^"]*)")/', $original_image_html, $matches)) {
            $original_image_html = str_replace($matches['entire'], '', $original_image_html);
            $classes = array_merge($classes, explode(' ', $matches['class']));
        }

        // Store our original <img /> tag directly
        $images[] = $original_image_html;

        // Grab the URL src and get the file extension
        $original_image_width = (int)$original_image['width'];
        $original_image_height = (int)$original_image['height'];
        $original_image_src = $original_image['src'];

        $original_extension = mb_strtolower(pathinfo($original_image_src, PATHINFO_EXTENSION));

        if ($this->supports_webp()) {
            // TODO: This should have a better check to see if the image exists
            // Assume that a WebP was created
            $images[] = [
                'srcset' => $original_image_src . '.webp',
                'type' => 'image/webp',
            ];
        }

        // See if a 2x was registered
        $size_2x = $size . '-2x';
        if ($this->does_image_size_exist($size_2x)) {

            // Grab the 2x version
            $image_2x = $this->get_attachment_image_src($attachment_id, $size_2x);
            if ($image_2x && is_array($image_2x)) {

                $image_2x_src = $image_2x['src'];
                $image_2x_width = (int)$image_2x['width'];
                $image_2x_height = (int)$image_2x['height'];

                // Only add the 2x if both the height and width are greater
                if ($image_2x_height > $original_image_height && $image_2x_width > $original_image_width) {
                    $new = [
                        'srcset' => $image_2x_src,
                        'media' => '(min-resolution: 150dpi)',
                    ];

                    // Append the MIME
                    switch ($original_extension) {
                        case 'jpg':
                        case 'jpeg':
                            $new['type'] = 'image/jpeg';
                            break;

                        case 'gif':
                            $new['type'] = 'image/gif';
                            break;

                        case 'png':
                            $new['type'] = 'image/png';
                            break;
                    }
                    $images[] = $new;

                    if ($this->supports_webp()) {
                        // Also assume that a webp version exists, too.
                        $images[] = [
                            'srcset' => $image_2x_src . '.webp',
                            'type' => 'image/webp',
                        ];
                    }
                }
            }
        }

        // Convert to HTML
        $picture = $this->create_picture_tag($images, $classes);

        // Guarantee HTML safety
        return $this->sanitize_picture_tag($picture);

    }
}