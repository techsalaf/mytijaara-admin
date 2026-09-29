<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Item;
use App\Models\TempProduct;

class NullSafeProductGalleryTest extends TestCase
{
    public function test_item_images_null_safe_accessors()
    {
        $item = new Item();
        $item->images = null;

        $this->assertNull($item->images);
        $this->assertIsArray($item->images_full_url);
        $this->assertEmpty($item->images_full_url);

        $images = is_array($item['images']) ? $item['images'] : [];
        $this->assertIsArray($images);
        $this->assertEmpty($images);

        $count = 0;
        if (!empty($item->images) && is_iterable($item->images)) {
            foreach ($item->images as $img) {
                $count++;
            }
        }
        $this->assertEquals(0, $count);
    }

    public function test_temp_product_images_null_safe_accessors()
    {
        $temp = new TempProduct();
        $temp->images = null;

        $this->assertNull($temp->images);
        $this->assertIsArray($temp->images_full_url);
        $this->assertEmpty($temp->images_full_url);

        $images = is_array($temp->images) ? $temp->images : [];
        $this->assertIsArray($images);
        $this->assertEmpty($images);
    }
}
