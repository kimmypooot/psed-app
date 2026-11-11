<?php

namespace Tests\Feature;

use App\Services\DirectoryUnavailable;
use App\Services\ImisDirectory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesImisDirectory;
use Tests\TestCase;

class ImisDirectoryTest extends TestCase
{
    use FakesImisDirectory;

    private ImisDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = new ImisDirectory;
    }

    public function test_it_is_off_until_cares_is_told_where_imis_is(): void
    {
        Http::fake();
        $this->assertFalse($this->directory->configured());

        $this->expectException(DirectoryUnavailable::class);
        $this->expectExceptionMessage('not set up');

        try {
            $this->directory->employees();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_asks_imis_with_the_token_and_reads_the_people(): void
    {
        $this->fakeImisDirectory();

        $this->assertTrue($this->directory->configured());
        $this->assertCount(10, $this->directory->employees());
        $this->assertSame('Ryan Jose M. Estoque', $this->directory->find(1)['name']);
        $this->assertNull($this->directory->find(999));

        Http::assertSent(fn (Request $r) => $r->url() === self::IMIS_URL.'/api/v1/cares/employees' && $r->hasHeader('Authorization', 'Bearer directory-token'));
    }

    public function test_staff_are_listed_by_office_code(): void
    {
        $this->fakeImisDirectory();

        $this->assertSame([1, 3, 5], $this->directory->staffOf('ESFO')->pluck('id')->all());
        $this->assertSame([2, 6, 7], $this->directory->staffOf('SFO')->pluck('id')->all());
        $this->assertSame([], $this->directory->staffOf('NOPE')->all());
    }

    public function test_the_head_is_the_director_ii_and_a_designation_is_called_concurrent_or_caretaker(): void
    {
        $this->fakeImisDirectory();

        $samar = $this->directory->headOf('SFO');
        $this->assertSame('Rey Albert B. Uy', $samar['employee']['name']);
        $this->assertNull($samar['capacity']);
        $this->assertNull($samar['capacity_label']);
        $this->assertSame('Director II', $samar['title'], 'the regular head signs by their position');
        $this->assertTrue($samar['is_director_ii']);

        // The same Director II heads Eastern Samar too, at the same time: IMIS says "oic", CARES says Concurrent.
        $eastern = $this->directory->headOf('ESFO');
        $this->assertSame('Rey Albert B. Uy', $eastern['employee']['name']);
        $this->assertSame('oic', $eastern['capacity']);
        $this->assertSame('Concurrent', $eastern['capacity_label'], 'never "Officer-in-Charge"');
        $this->assertSame('Director II', $eastern['title'], 'reports are signed by position alone: no (Concurrent)');
        $this->assertTrue($eastern['is_director_ii'], 'a concurrent Director II is still a Director II');

        $this->assertNull($this->directory->headOf('PSED'), 'an office IMIS shows no head for');
    }

    public function test_a_caretaker_below_director_ii_signs_with_their_position_and_the_designation(): void
    {
        $people = $this->imisPeople();
        $people['heads'] = [['office_code' => 'WLSO', 'user_id' => 3, 'capacity' => 'caretaker', 'capacity_label' => 'Caretaker']];
        $this->fakeImisDirectory($people);

        $head = $this->directory->headOf('WLSO');

        $this->assertSame('Caretaker', $head['capacity_label']);
        $this->assertSame('Supervising Human Resource Specialist', $head['title'], 'position alone, even for a caretaker');
        $this->assertFalse($head['is_director_ii'], 'not a Director II: the SG 22 case');
    }

    public function test_people_lists_are_limited_to_field_and_satellite_offices(): void
    {
        $this->fakeImisDirectory();

        $this->assertSame([1, 2, 3, 5, 6, 7], $this->directory->fieldOfficeEmployees()->keys()->sort()->values()->all(), 'PSED\'s Pedro (4) is not listed');
        $this->assertNull($this->directory->findInFieldOffice(4), 'a regional support unit employee is not accepted');
        $this->assertSame(4, $this->directory->find(4)['id'], 'though IMIS does know them');
    }

    public function test_the_directors_are_the_regional_offices_director_iv_and_iii_and_no_one_else(): void
    {
        $people = $this->imisPeople();
        // A Director III somewhere else, and a Director IV-like title in a Field Office, are not the regional office's.
        $people['data'][] = ['id' => 12, 'name' => 'Elsewhere D. Three', 'first_name' => null, 'middle_name' => null, 'last_name' => null, 'name_extension' => null,
            'position' => 'Director III', 'salary_grade' => 27, 'office_code' => 'LSD', 'office_name' => 'Legal Services Division', 'office_type' => 'regional_support_unit'];
        $this->fakeImisDirectory($people);

        $this->assertSame([8, 9], $this->directory->directors()->keys()->sort()->values()->all(), 'not Carla (6th grade, same office), not Rey (Director II), not the other division\'s');
        $this->assertSame(8, $this->directory->findDirector(8)['id']);
        $this->assertNull($this->directory->findDirector(10), 'an ordinary employee of the regional office');
        $this->assertNull($this->directory->findDirector(2), 'a Director II is not one of them');
        $this->assertNull($this->directory->findDirector(12), 'nor a Director III of another office');
        $this->assertSame(8, $this->directory->matchDirector('alicia mendoza')['id'] ?? null);
        $this->assertNull($this->directory->matchDirector('Ryan Jose Estoque'), 'a Field Office employee is not a director');
        $this->assertNull($this->directory->match('Alicia R. Mendoza'), 'and a director is not in the Field Office pool');
    }

    public function test_lists_are_ordered_by_salary_grade_with_the_highest_first(): void
    {
        $this->fakeImisDirectory();

        $this->assertSame([8, 9], $this->directory::byGrade($this->directory->directors())->pluck('id')->all(), 'Director IV (SG 28) above Director III (SG 27)');
        $this->assertSame([2, 3, 1, 7, 5, 6], $this->directory::byGrade($this->directory->fieldOfficeEmployees())->pluck('id')->all());
    }

    public function test_an_employee_without_a_grade_goes_last_and_equal_grades_are_ordered_by_name(): void
    {
        $people = [['id' => 1, 'name' => 'Zed', 'salary_grade' => 10], ['id' => 2, 'name' => 'Amy', 'salary_grade' => 10], ['id' => 3, 'name' => 'Nobody', 'salary_grade' => null], ['id' => 4, 'name' => 'Top', 'salary_grade' => 26]];

        $this->assertSame([4, 2, 1, 3], ImisDirectory::byGrade(collect($people))->pluck('id')->all());
    }

    public function test_staff_can_be_listed_without_the_head(): void
    {
        $this->fakeImisDirectory();

        $this->assertSame([2, 6, 7], $this->directory->staffOf('SFO')->pluck('id')->all());
        $this->assertSame([6, 7], $this->directory->staffOf('SFO', withoutHead: true)->pluck('id')->all(), 'Samar\'s head (2) is left out');
        $this->assertSame([1, 3, 5], $this->directory->staffOf('ESFO', withoutHead: true)->pluck('id')->all(), 'Eastern Samar\'s head works in Samar, so no one to leave out');
    }

    public function test_a_concurrent_director_belongs_to_the_office_they_hold_as_well_as_their_own(): void
    {
        $this->fakeImisDirectory();   // Rey Albert B. Uy works in Samar and holds Eastern Samar concurrently

        $roster = $this->directory->fieldOfficeRoster();

        $this->assertContains(2, $roster['ESFO']['people']->keys()->all(), 'listed in the office he holds');
        $this->assertContains(2, $roster['SFO']['people']->keys()->all(), 'and in the one he works in');
        $this->assertSame('CSC Field Office - Eastern Samar', $roster['ESFO']['name']);
        $this->assertSame([1, 2, 3, 5], $roster['ESFO']['people']->keys()->sort()->values()->all());
        $this->assertSame([2, 6, 7], $roster['SFO']['people']->keys()->sort()->values()->all());
        $this->assertSame(['ESFO', 'SFO'], array_keys($roster), 'regional support units are not in it');
    }

    public function test_only_a_designation_adds_someone_to_another_office_and_only_to_field_and_satellite_offices(): void
    {
        $people = $this->imisPeople();
        $people['heads'] = [
            // A caretaker from elsewhere holds Eastern Samar: listed there.
            ['office_code' => 'ESFO', 'office_name' => 'CSC Field Office - Eastern Samar', 'office_type' => 'field_office', 'user_id' => 7, 'capacity' => 'caretaker', 'capacity_label' => 'Caretaker'],
            // A regular head belongs to the office already (nothing to add), and a head of a regional unit is not a Field Office person.
            ['office_code' => 'SFO', 'office_name' => 'CSC Field Office - Samar', 'office_type' => 'field_office', 'user_id' => 2, 'capacity' => null, 'capacity_label' => null],
            ['office_code' => 'ORD', 'office_name' => 'Office of the Regional Director', 'office_type' => 'regional_support_unit', 'user_id' => 1, 'capacity' => 'oic', 'capacity_label' => 'Officer-in-Charge'],
        ];
        $this->fakeImisDirectory($people);

        $roster = $this->directory->fieldOfficeRoster();

        $this->assertContains(7, $roster['ESFO']['people']->keys()->all());
        $this->assertNotContains(1, $roster['SFO']['people']->keys()->all(), 'heading a regional unit does not put Ryan in a Field Office');
        $this->assertArrayNotHasKey('ORD', $roster);
        $this->assertNotContains(2, $roster['ESFO']['people']->keys()->all(), 'no designation, no membership');
    }

    public function test_a_field_office_user_s_roster_is_their_own_office_including_who_holds_it(): void
    {
        $this->fakeImisDirectory();

        $this->assertSame(['ESFO'], array_keys($this->directory->fieldOfficeRoster('ESFO')));
        $this->assertSame(2, $this->directory->findInFieldOffice(2, 'ESFO')['id'], 'the concurrent Director II may be named by Eastern Samar');
        $this->assertSame(2, $this->directory->match('Rey Albert Uy', 'ESFO')['id'] ?? null);
        $this->assertNull($this->directory->findInFieldOffice(7, 'ESFO'));
    }

    public function test_a_field_office_user_s_lists_stay_inside_their_office(): void
    {
        $this->fakeImisDirectory();

        $this->assertSame([1, 2, 3, 5], $this->directory->fieldOfficeEmployees('ESFO')->keys()->sort()->values()->all(), 'Eastern Samar\'s own people, and the Director II who holds it concurrently');
        $this->assertSame(1, $this->directory->findInFieldOffice(1, 'ESFO')['id']);
        $this->assertNull($this->directory->findInFieldOffice(7, 'ESFO'), 'Samar\'s Ana is outside Eastern Samar');
        $this->assertSame(7, $this->directory->match('Ana Reyes')['id'] ?? null);
        $this->assertNull($this->directory->match('Ana Reyes', 'ESFO'));
    }
    public function test_a_written_name_finds_the_one_employee_it_stands_for(): void
    {
        $this->fakeImisDirectory();

        foreach (['Ryan Jose M. Estoque', 'RYAN JOSE M. ESTOQUE', 'ryan jose estoque', 'Ryan Jose  M Estoque', ' Estoque, Ryan Jose '] as $written) {
            $match = $this->directory->match($written);
            $this->assertSame(1, $match['id'] ?? null, "\"{$written}\" should be Ryan Jose M. Estoque");
        }

        $this->assertNull($this->directory->match('Pedro P. Dizon'), 'PSED is not a Field Office');
        $this->assertNull($this->directory->match('Nobody Known'));
        $this->assertNull($this->directory->match(''));
    }

    public function test_a_name_two_people_share_matches_nobody(): void
    {
        $this->fakeImisDirectory(); // "Juan Dela Cruz" and "Juan C. Dela Cruz" are the same once initials go

        $this->assertNull($this->directory->match('Juan Dela Cruz'));
    }

    public function test_names_are_formatted_for_signatures_and_lists(): void
    {
        $this->fakeImisDirectory();
        $person = $this->directory->find(1);

        $this->assertSame('RYAN JOSE M. ESTOQUE', ImisDirectory::signatureName($person));
        $this->assertSame('Ryan Jose M. Estoque · Senior Human Resource Specialist', ImisDirectory::label($person));
    }

    public function test_the_answer_is_cached_so_imis_is_not_asked_every_time(): void
    {
        $this->fakeImisDirectory();

        $this->directory->employees();
        $this->directory->headOf('SFO');
        $this->directory->find(2);

        Http::assertSentCount(1);
    }

    public function test_an_old_copy_is_used_when_imis_is_briefly_unreachable(): void
    {
        $this->fakeImisDirectory();
        $this->directory->employees();                       // fills the cache

        Cache::forget('imis.directory');                      // the fresh copy has expired...
        Http::fake(['*' => Http::response('down', 503)]);     // ...and IMIS is down

        $this->assertSame('Ryan Jose M. Estoque', $this->directory->find(1)['name'], 'the stale copy keeps things working');
    }

    public function test_with_no_copy_at_all_an_unreachable_imis_is_explained(): void
    {
        $this->imisIsDown();

        try {
            $this->directory->employees();
            $this->fail('Expected DirectoryUnavailable');
        } catch (DirectoryUnavailable $e) {
            $this->assertStringContainsString('check the CARES token in IMIS', $e->getMessage());
        }
    }

    public function test_a_connection_failure_is_explained_too(): void
    {
        config(['services.imis.api_url' => self::IMIS_URL, 'services.imis.api_token' => 'directory-token']);
        Cache::flush();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

        $this->expectException(DirectoryUnavailable::class);
        $this->expectExceptionMessage('could not be reached');

        $this->directory->employees();
    }

    public function test_forgetting_makes_the_next_read_ask_imis_again(): void
    {
        $this->fakeImisDirectory();
        $this->directory->employees();

        $this->directory->forget();
        $this->directory->employees();

        Http::assertSentCount(2);
    }
}
