<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\QuotationTemplate;
use App\Models\QuotationTemplateLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuotationTemplateLine> */
class QuotationTemplateLineFactory extends Factory
{
    public function definition(): array
    {
        return ['quotation_template_id' => QuotationTemplate::factory(), 'product_id' => Product::factory(), 'quantity' => 1, 'position' => 1];
    }
}
