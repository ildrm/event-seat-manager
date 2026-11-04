<?php

declare(strict_types=1);

namespace PESM\Domain\Venue;

use InvalidArgumentException;

/** Parameterized recipes, never fifty prebuilt stadium documents. */
final class PresetCatalog
{
    public const MAX_CAPACITY = 150000;

    public static function all(): array
    {
        $recipes = [
            ['cinema','central_aisle','Central aisle','راهروی مرکزی','rows',2],
            ['cinema','side_aisles','Side aisles','راهروهای کناری','rows',1],
            ['cinema','three_blocks','Three blocks','سه بلوک','rows',3],
            ['cinema','staggered_tiers','Staggered tiers','ردیف‌های پلکانی متناوب','rows',2],
            ['cinema','curved_tiers','Curved tiers','ردیف‌های منحنی پلکانی','arc',2],
            ['cinema','fan','Fan','بادبزنی','arc',3],
            ['cinema','stalls_balcony','Stalls and balcony','همکف و بالکن','rows',2],
            ['cinema','recliner_pods','Paired recliner pods','جایگاه‌های دونفره','table',2],
            ['theater','proscenium','Proscenium','صحنه پیش‌آمده','arc',3],
            ['theater','thrust','Three-sided thrust','صحنه سه‌طرفه','thrust',3],
            ['theater','arena','Four-sided arena','صحنه چهارطرفه','perimeter',4],
            ['theater','circular','Circular theater','تئاتر مدور','radial',1],
            ['theater','traverse','Opposing traverse','صحنه دوسویه','perimeter',2],
            ['theater','horseshoe','Horseshoe','نعل اسبی','arc',3],
            ['theater','amphitheater','Amphitheater','آمفی‌تئاتر','arc',4],
            ['theater','black_box','L-shaped black box','بلک‌باکس ال‌شکل','lshape',2],
            ['conference','plenary_fan','Plenary fan','نشست بادبزنی','arc',3],
            ['conference','classroom','Classroom','کلاسی','rows',3],
            ['conference','chevrons','Chevrons','ردیف‌های جناقی','chevron',2],
            ['conference','u','U shape','یو شکل','perimeter',3],
            ['conference','double_u','Double U','یو دوبل','perimeter',3],
            ['conference','hollow_square','Hollow square','مربع توخالی','perimeter',4],
            ['conference','boardroom','Boardroom','اتاق هیئت‌مدیره','perimeter',2],
            ['conference','cabaret','Cabaret clusters','میزهای کاباره','table',6],
            ['restaurant','round_banquet','Round banquet','ضیافت میز گرد','table',8],
            ['restaurant','rectangular_banquet','Rectangular banquet','ضیافت مستطیلی','table_rect',8],
            ['restaurant','communal','Communal tables','میز اشتراکی','table_rect',16],
            ['restaurant','booths','Perimeter booths','غرفه‌های پیرامونی','booth',4],
            ['restaurant','cafe_pairs','Café pairs','میز دونفره کافه','table',2],
            ['restaurant','mixed_tables','Mixed table sizes','میزهای ترکیبی','table',6],
            ['restaurant','terrace','Terrace rows','ردیف‌های تراس','table_rect',4],
            ['restaurant','dining_aisle','Central dining aisle','راهروی مرکزی پذیرایی','table',8],
            ['fashion','straight','Straight runway','ران‌وی مستقیم','runway',2],
            ['fashion','t','T runway','ران‌وی تی','runway',3],
            ['fashion','x','X runway','ران‌وی ضربدری','runway',4],
            ['fashion','u','U runway','ران‌وی یو','runway',3],
            ['fashion','loop','Rectangular loop','ران‌وی حلقه مستطیلی','perimeter',4],
            ['fashion','radial','Radial podium','سکوی شعاعی','radial',4],
            ['sports','single_stand','Single grandstand','سکوی یک‌طرفه','rows',1],
            ['sports','opposing','Opposing stands','سکوهای روبه‌رو','perimeter',2],
            ['sports','three_sides','Three-sided stands','سکوهای سه‌طرفه','perimeter',3],
            ['sports','four_sides','Four-sided stands','سکوهای چهارطرفه','perimeter',4],
            ['sports','oval','Oval bowl','کاسه بیضی','bowl',8],
            ['sports','retractable','Retractable floor seating','صندلی‌های جمع‌شونده زمین','rows',4],
            ['stadium','oval','Oval bowl','ورزشگاه بیضی','bowl',16],
            ['stadium','corner_gaps','Separate corner stands','سکوها با فاصله گوشه‌ها','bowl',4],
            ['stadium','continuous','Continuous bowl','کاسه پیوسته','bowl',12],
            ['stadium','open_horseshoe','Open horseshoe','ورزشگاه نعل اسبی','bowl',10],
            ['stadium','two_tiers','Two tiers','دو طبقه','bowl',16],
            ['stadium','boxes','Three tiers with boxes','سه طبقه با جایگاه اختصاصی','bowl',18],
        ];
        $out = [];
        foreach ($recipes as [$category,$slug,$label,$fa,$generator,$shape]) {
            $grouped = in_array($generator, ['table','table_rect','booth'], true);
            $levels = $slug === 'two_tiers' || $slug === 'stalls_balcony' ? 2 : ($slug === 'boxes' ? 3 : 1);
            $sectionCount = $category === 'stadium' ? $shape : ($category === 'sports' && $generator === 'bowl' ? 8 : $levels);
            $defaults = ['workflow'=>'exact','capacity'=>900,'rows'=>20,'columns'=>30,'sections'=>$sectionCount,
                'levels'=>$levels,'spacing'=>600,'row_spacing'=>1000,'aisle_width'=>1400,
                'blocks'=>$grouped ? 1 : min($shape, 4),'group_size'=>$grouped ? $shape : 8,'group_count'=>100,
                'min_group_size'=>1,'fixed_group_size'=>false,'sale_mode'=>'both','accessible_every'=>0];
            $controls = ['capacity','workflow','sections','levels','spacing','row_spacing','aisle_width'];
            array_push($controls, ...($grouped ? ['group_count','group_size','min_group_size','fixed_group_size','sale_mode'] : ['rows','columns','blocks']));
            $out[] = ['id'=>$category . '_' . $slug,'label'=>$label,'label_fa'=>$fa,'category'=>$category,
                'generator'=>$generator,'variant'=>$slug,'shape'=>$shape,'version'=>1,'min_capacity'=>1,
                'max_capacity'=>self::MAX_CAPACITY,'defaults'=>$defaults,'controls'=>$controls];
        }
        return $out;
    }

    public static function get(string $id): array
    {
        foreach (self::all() as $preset) {
            if ($preset['id'] === $id) { return $preset; }
        }
        throw new InvalidArgumentException('Unknown venue configuration.');
    }
}
