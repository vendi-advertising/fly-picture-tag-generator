<?php

namespace Vendi\ImageUtilities\FlyPictureTagGenerator;

class PictureTagUtility
{
    private static $instance = null;

    private $all_image_sizes = null;

    private const WEIGHT_FILE_FORMAT = 1;
    private const WEIGHT_DPI = 10;
    private const FILE_FORMAT_WEBP = 2;
    private const FILE_FORMAT_JPEG = 1;

    private const WEIGHT_FINAL_WEBP_2x = self::WEIGHT_DPI * 2 + self::WEIGHT_FILE_FORMAT * self::FILE_FORMAT_WEBP;
    private const WEIGHT_FINAL_WEBP_1x = self::WEIGHT_DPI * 1 + self::WEIGHT_FILE_FORMAT * self::FILE_FORMAT_WEBP;
    private const WEIGHT_FINAL_JPEG_2x = self::WEIGHT_DPI * 2 + self::WEIGHT_FILE_FORMAT * self::FILE_FORMAT_JPEG;
    private const WEIGHT_FINAL_JPEG_1x = self::WEIGHT_DPI * 1 + self::WEIGHT_FILE_FORMAT * self::FILE_FORMAT_JPEG;

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

    protected function does_file_extension_support_webp(string $file_extension): bool
    {
        // The conversion library doesn't support GIFs
        // See: https://github.com/rosell-dk/webp-convert/issues/73
        return in_array($file_extension, ['jpeg', 'jpg', 'png']);
    }

    protected function does_system_support_webp(): bool
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
                    $html .= " $name=".'"'.esc_attr($value).'"';
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

        if (!is_array($classes)) {
            $classes = [$classes];
        }

        unset($attr['class']);

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
        $does_image_support_webp = $this->does_file_extension_support_webp($original_extension);

        $new_images = [];

        if ($does_image_support_webp && $this->does_system_support_webp()) {
            // Assume that a WebP was created, however we added a hook at the end that
            // allows users to validate on their own.
            $new_images[self::WEIGHT_FINAL_WEBP_1x] = [
                'srcset' => $original_image_src.'.webp',
                'type' => 'image/webp',
            ];
        }

        // See if a 2x was registered
        $size_2x = $size.'-2x';
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

                        case 'png':
                            $new['type'] = 'image/png';
                            break;
                    }
                    $new_images[self::WEIGHT_FINAL_JPEG_2x] = $new;

                    if ($does_image_support_webp && $this->does_system_support_webp()) {
                        // Also assume that a webp version exists, too.
                        $new_images[self::WEIGHT_FINAL_WEBP_2x] = [
                            'srcset' => $image_2x_src.'.webp',
                            'type' => 'image/webp',
                            'media' => '(min-resolution: 150dpi)',
                        ];
                    }
                }
            }
        }

        krsort($new_images);
        $images = array_merge($images, $new_images);

        assert(function_exists('apply_filters'));
        $images = apply_filters('vendi/picture-tag/images', $images, $attachment_id, $size, $crop, $attr);

        if (!is_array($images) || !count($images)) {
            return '';
        }

        // Convert to HTML
        $picture = $this->create_picture_tag($images, $classes);

        // Guarantee HTML safety
        return $this->sanitize_picture_tag($picture);
    }
}
