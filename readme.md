# Readme

With support from [Fly Dynamic Image Resizer](https://wordpress.org/plugins/fly-dynamic-image-resizer/),
allows for outputting of HTML `<picture>` tags with multiple formats such as WebP, and multiple sizes
such as 2x.

```php
vendi_fly_get_attachment_picture(get_post_thumbnail_id($location->ID), 'location-listing')
```

Outputs:

```
<picture>
    <source srcset="https://example.com/wp-content/uploads/fly-images/123/ABC-scaled-300x300-ct.jpeg.webp" type="image/webp" media="(min-resolution: 150dpi)" />
    <source srcset="https://example.com/wp-content/uploads/fly-images/123/ABC-scaled-300x300-ct.jpeg" media="(min-resolution: 150dpi)" type="image/jpeg" />
    <source srcset="https://example.com/wp-content/uploads/fly-images/123/ABC-scaled-150x150-ct.jpeg.webp" type="image/webp" />
    <img width="150" height="150" src="https://example.com/wp-content/uploads/fly-images/123/ABC-scaled-150x150-ct.jpeg" alt="ABC" loading="lazy" />
</picture>
```
