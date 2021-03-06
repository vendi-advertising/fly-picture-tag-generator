<?php

use Vendi\ImageUtilities\FlyPictureTagGenerator\PictureTagUtility;

if (!function_exists('vendi_fly_get_attachment_picture')) {
    function vendi_fly_get_attachment_picture($attachment_id = 0, $size = '', $crop = null, $attr = []): string
    {
        return PictureTagUtility::get_instance()->get_attachment_picture($attachment_id, $size, $crop, $attr);
    }
}
