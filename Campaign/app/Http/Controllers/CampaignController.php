<?php

namespace App\Http\Controllers;

use App\Banner;
use App\Campaign;
use App\CampaignBanner;
use App\CampaignSegment;
use App\Contracts\SegmentAggregator;
use App\Contracts\SegmentException;
use App\Country;
use App\Http\Requests\CampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Http\Showtime\ControllerShowtimeResponse;
use App\Http\Showtime\Showtime;
use App\Schedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use View;
use HTML;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Yajra\Datatables\Datatables;

class CampaignController extends Controller
{
    private $beamJournalConfigured;

    private $showtime;

    public function __construct(Showtime $showtime)
    {
        $this->beamJournalConfigured = !empty(config('services.remp.beam.segments_addr'));
        $this->showtime = $showtime;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(SegmentAggregator $segmentAggregator)
    {
        $availableSegments = $this->getAllSegments($segmentAggregator)->pluck('name', 'code');
        $segments = CampaignSegment::get()->mapWithKeys(function ($item) use ($availableSegments) {
            return [$item->code => $availableSegments->get($item->code) ?? $item->code];
        });
        $variants = CampaignBanner::with('banner')
            ->whereNotNull('banner_id')
            ->get()
            ->pluck('banner.name', 'banner.id');

        return response()->format([
            'html' => view('campaigns.index', [
                'beamJournalConfigured' => $this->beamJournalConfigured,
                'segments' => $segments,
                'variants' => $variants,
            ]),
            'json' => CampaignResource::collection(Campaign::paginate()),
        ]);
    }

    public function json(Datatables $dataTables, SegmentAggregator $segmentAggregator)
    {
        $campaigns = Campaign::select('campaigns.*')
            ->with(['segments', 'countries', 'campaignBanners', 'campaignBanners.banner', 'schedules']);

        $segments = $this->getAllSegments($segmentAggregator)->pluck('name', 'code');

        return $dataTables->of($campaigns)
            ->addColumn('actions', function (Campaign $campaign) {
                return [
                    'edit' => route('campaigns.edit', $campaign),
                    'copy' => route('campaigns.copy', $campaign),
                    'stats' => route('campaigns.stats', $campaign),
                    'compare' => route('comparison.add', $campaign),
                ];
            })
            ->addColumn('name', function (Campaign $campaign) {
                return [
                    'url' => route('campaigns.edit', ['campaign' => $campaign]),
                    'text' => $campaign->name,
                ];
            })
            ->filterColumn('name', function (Builder $query, $value) {
                $query->where('campaigns.name', 'like', "%{$value}%");
            })
            ->addColumn('variants', function (Campaign $campaign) {
                $data = $campaign->campaignBanners->all();
                $variants = [];

                /** @var CampaignBanner $variant */
                foreach ($data as $variant) {
                    $proportion = $variant->proportion;
                    if ($proportion === 0) {
                        continue;
                    }

                    // handle control group
                    if ($variant->control_group === 1) {
                        $variants[] = "Control Group&nbsp;({$proportion}%)";
                        continue;
                    }

                    // handle variants with banner
                    $link = link_to(
                        route('banners.edit', $variant->banner_id),
                        $variant->banner->name
                    );

                    $variants[] = "{$link}&nbsp;({$proportion}%)";
                }

                return $variants;
            })
            ->filterColumn('variants', function (Builder $query, $value) {
                $values = explode(',', $value);
                $filterQuery = \DB::table('campaign_banners')
                    ->select(['campaign_banners.campaign_id'])
                    ->whereIn('campaign_banners.banner_id', $values)
                    ->where('campaign_banners.proportion', '>', 0);
                $query->whereIn('campaigns.id', $filterQuery);
            })
            ->addColumn('segments', function (Campaign $campaign) use ($segments) {
                $segmentNames = [];

                $exclusiveIcon = '<i class="zmdi zmdi-eye-off" title="User must not be member of segment to see the campaign."></i>';
                $inclusiveIcon = '<i class="zmdi zmdi-eye primary-color" title="User needs to be member of segment to see the campaign."></i>';

                foreach ($campaign->segments as $segment) {
                    $icon = $segment->inclusive ? $inclusiveIcon : $exclusiveIcon;

                    if ($segments->get($segment->code)) {
                        $segmentNames[] = "{$icon} <span title='{$segment->code}'>{$segments->get($segment->code)}</span></em>";
                    } else {
                        $segmentNames[] = "{$icon} <span title='{$segment->code}'>{$segment->code}</span></em>";
                    }
                }

                return $segmentNames;
            })
            ->filterColumn('segments', function (Builder $query, $value) {
                $values = explode(',', $value);
                $filterQuery = \DB::table('campaign_segments')
                    ->select(['campaign_segments.campaign_id'])
                    ->whereIn('campaign_segments.code', $values);
                $query->whereIn('campaigns.id', $filterQuery);
            })
            ->addColumn('countries', function (Campaign $campaign) {
                return implode(' ', $campaign->countries->pluck('name')->toArray());
            })
            ->addColumn('active', function (Campaign $campaign) {
                $active = $campaign->active;
                return view('campaigns.partials.activeToggle', [
                    'id' => $campaign->id,
                    'active' => $active,
                    'title' => $active ? 'Deactivate campaign' : 'Activate campaign'
                ])->render();
            })
            ->addColumn('is_running', function (Campaign $campaign) {
                foreach ($campaign->schedules as $schedule) {
                    if ($schedule->isRunning()) {
                        return true;
                    }
                }
                return false;
            })
            ->addColumn('signed_in', function (Campaign $campaign) {
                return $campaign->signedInLabel();
            })
            ->addColumn('devices', function (Campaign $campaign) {
                return count($campaign->devices) == count($campaign->getAllDevices()) ? 'all' : implode(' ', $campaign->devices);
            })
            ->rawColumns(['name.text', 'actions', 'active', 'signed_in', 'once_per_session', 'variants', 'is_running', 'segments'])
            ->setRowId('id')
            ->make(true);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param SegmentAggregator $segmentAggregator
     * @return \Illuminate\Http\Response
     */
    public function create(SegmentAggregator $segmentAggregator)
    {
        $campaign = new Campaign();

        [
            $campaign,
            $bannerId,
            $variants,
            $selectedCountries,
            $countriesBlacklist
        ] = $this->processOldCampaign($campaign, old());

        return view('campaigns.create', [
            'campaign' => $campaign,
            'bannerId' => $bannerId,
            'variants' => $variants,
            'selectedCountries' => $selectedCountries,
            'countriesBlacklist' => $countriesBlacklist,
            'banners' => Banner::all(),
            'availableCountries' => Country::all(),
            'segments' => $this->getAllSegments($segmentAggregator)
        ]);
    }

    public function copy(Campaign $sourceCampaign, SegmentAggregator $segmentAggregator)
    {
        $sourceCampaign->load('banners', 'campaignBanners', 'segments', 'countries');
        $campaign = $sourceCampaign->replicate();

        flash(sprintf('Form has been pre-filled with data from campaign "%s"', $sourceCampaign->name))->info();

        [
            $campaign,
            $bannerId,
            $variants,
            $selectedCountries,
            $countriesBlacklist
        ] = $this->processOldCampaign($campaign, old());

        return view('campaigns.create', [
            'campaign' => $campaign,
            'bannerId' => $bannerId,
            'variants' => $variants,
            'selectedCountries' => $selectedCountries,
            'countriesBlacklist' => $countriesBlacklist,
            'banners' => Banner::all(),
            'availableCountries' => Country::all(),
            'segments' => $this->getAllSegments($segmentAggregator)
        ]);
    }

    /**
     * Ajax validate form method.
     *
     * @param CampaignRequest|Request $request
     * @return \Illuminate\Http\Response
     */
    public function validateForm(CampaignRequest $request)
    {
        return response()->json(false);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param CampaignRequest|Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(CampaignRequest $request)
    {
        $campaign = new Campaign();

        $this->saveCampaign($campaign, $request->all());

        $message = ['success' => sprintf('Campaign [%s] was created', $campaign->name)];

        // (de)activate campaign (based on flag or new schedule)
        $message['warning'] = $this->processCampaignActivation(
            $campaign,
            $request->get('activation_mode'),
            $request->get('active', false),
            $request->get('new_schedule_start_time'),
            $request->get('new_schedule_end_time')
        );

        return response()->format([
            'html' => $this->getRouteBasedOnAction(
                $request->get('action'),
                [
                    self::FORM_ACTION_SAVE_CLOSE => 'campaigns.index',
                    self::FORM_ACTION_SAVE => 'campaigns.edit',
                ],
                $campaign
            )->with($message),
            'json' => new CampaignResource($campaign),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Campaign  $campaign
     * @return \Illuminate\Http\Response
     */
    public function show(Campaign $campaign)
    {
        return response()->format([
            'html' => view('campaigns.show', [
                'campaign' => $campaign,
            ]),
            'json' => new CampaignResource($campaign),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Campaign $campaign
     * @param SegmentAggregator $segmentAggregator
     * @return \Illuminate\Http\Response
     */
    public function edit(Campaign $campaign, SegmentAggregator $segmentAggregator)
    {
        [
            $campaign,
            $bannerId,
            $variants,
            $selectedCountries,
            $countriesBlacklist
        ] = $this->processOldCampaign($campaign, old());

        return view('campaigns.edit', [
            'campaign' => $campaign,
            'bannerId' => $bannerId,
            'variants' => $variants,
            'selectedCountries' => $selectedCountries,
            'countriesBlacklist' => $countriesBlacklist,
            'banners' => Banner::all(),
            'availableCountries' => Country::all()->keyBy("iso_code"),
            'segments' => $this->getAllSegments($segmentAggregator)
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param CampaignRequest|Request $request
     * @param  \App\Campaign $campaign
     * @return \Illuminate\Http\Response
     */
    public function update(CampaignRequest $request, Campaign $campaign)
    {
        $this->saveCampaign($campaign, $request->all());

        $message = ['success' => sprintf('Campaign [%s] was updated.', $campaign->name)];

        // (de)activate campaign (based on flag or new schedule)
        $message['warning'] = $this->processCampaignActivation(
            $campaign,
            $request->get('activation_mode'),
            $request->get('active', false),
            $request->get('new_schedule_start_time'),
            $request->get('new_schedule_end_time')
        );

        return response()->format([
            'html' => $this->getRouteBasedOnAction(
                $request->get('action'),
                [
                        self::FORM_ACTION_SAVE_CLOSE => 'campaigns.index',
                        self::FORM_ACTION_SAVE => 'campaigns.edit',
                    ],
                $campaign
            )->with($message),
            'json' => new CampaignResource($campaign),
        ]);
    }

    /**
     * (De)Activate campaign (based on flag or new schedule).
     *
     * If `$activationMode` is 'activate-schedule' and new schedule has start time, create new schedule.
     * Otherwise activate / deactivate schedules - action based on provided `$activate` flag.
     *
     * @param $campaign
     * @param $activationMode
     * @param null $activate
     * @param null $newScheduleStartTime
     * @param null $newScheduleEndTime
     * @return null|string
     */
    private function processCampaignActivation(
        $campaign,
        $activationMode,
        $activate = null,
        $newScheduleStartTime = null,
        $newScheduleEndTime = null
    ): ?string {
        if ($activationMode === 'activate-schedule'
            && !is_null($newScheduleStartTime)) {
            $schedule = new Schedule();
            $schedule->campaign_id = $campaign->id;
            $schedule->start_time = $newScheduleStartTime;
            $schedule->end_time = $newScheduleEndTime;
            $schedule->save();
            return sprintf("Schedule with start time '%s' added", Carbon::parse($schedule->start_time)->toDayDateTimeString());
        } else {
            return $this->toggleSchedules($campaign, $activate);
        }
    }

    /**
     * Toggle campaign status - create or stop schedules.
     *
     * If campaign is not active, activate it:
     * - create new schedule with status executed (it wasn't planned).
     *
     * If campaign is active, deactivate it:
     * - stop all running or planned schedules.
     *
     * @param Campaign $campaign
     * @return JsonResponse
     */
    public function toggleActive(Campaign $campaign): JsonResponse
    {
        $activate = false;
        if (!$campaign->active) {
            $activate = true;
        }

        $this->toggleSchedules($campaign, $activate);

        // TODO: maybe add message from toggleSchedules to response?
        return response()->json([
            'active' => $campaign->active
        ]);
    }

    /**
     * Toggle schedules of campaign.
     *
     * When `activate` argument is not passed, no change is triggered.
     *
     * @param Campaign $campaign
     * @param null|boolean $activate
     * @return null|string Returns message about schedules state change.
     */
    private function toggleSchedules(Campaign $campaign, $activate = null): ?string
    {
        // do not change schedules when there is no `activate` order
        if (is_null($activate)) {
            return null;
        }

        $schedulesChangeMsg = null;
        if ($activate) {
            $activated = $this->startCampaignSchedule($campaign);
            if ($activated) {
                $schedulesChangeMsg = "Campaign was activated and is running.";
            }
        } else {
            $stopped = $this->stopCampaignSchedule($campaign);
            if ($stopped) {
                $schedulesChangeMsg = "Campaign was deactivated, all schedules were stopped.";
            }
        }

        return $schedulesChangeMsg;
    }


    /**
     * If no campaign's schedule is running, start new one.
     *
     * @param Campaign $campaign
     * @return bool Returns true if new schedule was added.
     */
    private function startCampaignSchedule(Campaign $campaign)
    {
        $activated = false;
        if (!$campaign->schedules()->running()->count()) {
            $schedule = new Schedule();
            $schedule->campaign_id = $campaign->id;
            $schedule->start_time = Carbon::now();
            $schedule->status = Schedule::STATUS_EXECUTED;
            $schedule->save();
            $activated = true;
        }

        return $activated;
    }

    /**
     * Stop all campaign schedules.
     *
     * @param Campaign $campaign
     * @return bool Returns true if any schedule was running and was stopped.
     */
    private function stopCampaignSchedule(Campaign $campaign): bool
    {
        $stopped = false;
        /** @var Schedule $schedule */
        foreach ($campaign->schedules()->runningOrPlanned()->get() as $schedule) {
            $schedule->status = Schedule::STATUS_STOPPED;
            $schedule->end_time = Carbon::now();
            $schedule->save();
            $stopped = true;
        }
        return $stopped;
    }

    /**
     * Returns countries array ready to sync with campaign_country pivot table
     *
     * @param array $countries
     * @param bool $blacklist
     * @return array
     */
    private function processCountries(array $countries, bool $blacklist): array
    {
        $processed = [];

        foreach ($countries as $cid) {
            $processed[$cid] = ['blacklisted' => $blacklist];
        }

        return $processed;
    }


    /**
     * @param Request                    $request
     * @param Showtime                   $showtime
     * @param ControllerShowtimeResponse $controllerShowtimeResponse
     *
     * @return JsonResponse
     */
    public function showtime(
        Request $request,
        Showtime $showtime,
        ControllerShowtimeResponse $controllerShowtimeResponse
    ) {
        $showtime->setRequest($request);
        $data = $request->get('data');
        $callback = $request->get('callback');

        if ($data === null || $callback === null) {
            return response()->json(['errors' => ['invalid request, data or callback params missing']], 400);
        }

        return $showtime->showtime($data, $callback, $controllerShowtimeResponse);
    }

    public function saveCampaign(Campaign $campaign, array $data)
    {
        $campaign->fill($data);
        $campaign->save();

        if (!empty($data['variants_to_remove'])) {
            $campaign->removeVariants($data['variants_to_remove']);
        }

        $campaign->storeOrUpdateVariants($data['variants']);

        $campaign->countries()->sync(
            $this->processCountries(
                $data['countries'] ?? [],
                (bool)$data['countries_blacklist']
            )
        );

        $segments = $data['segments'] ?? [];

        foreach ($segments as $segment) {
            /** @var CampaignSegment $campaignSegment */
            CampaignSegment::firstOrCreate([
                'campaign_id' => $campaign->id,
                'code' => $segment['code'],
                'provider' => $segment['provider'],
                'inclusive' => $segment['inclusive']
            ]);
        }

        if (isset($data['removedSegments'])) {
            CampaignSegment::destroy($data['removedSegments']);
        }
    }

    public function processOldCampaign(Campaign $campaign, array $data)
    {
        $campaign->fill($data);

        // parse old segments data
        $segments = [];
        $segmentsData = isset($data['segments']) ? $data['segments'] : $campaign->segments->toArray();
        $removedSegments = isset($data['removedSegments']) ? $data['removedSegments'] : [];

        foreach ($segmentsData as $segment) {
            if (is_null($segment['id']) || !array_search($segment['id'], $removedSegments)) {
                $segments[] = $campaign->segments()->make($segment);
            }
        }
        $campaign->setRelation('segments', collect($segments));

        // parse selected countries
        $countries = $campaign->countries->toArray();
        $selectedCountries = $data['countries'] ?? array_map(function ($country) {
            return $country['iso_code'];
        }, $countries);

        // countries blacklist?
        $blacklisted = 0;
        foreach ($countries as $country) {
            $blacklisted = (int)$country['pivot']['blacklisted'];
        }

        // main banner
        if (array_key_exists('banner_id', $data)) {
            $bannerId = $data['banner_id'];
        } elseif (!$campaign->campaignBanners->isEmpty()) {
            $bannerId = optional($campaign->campaignBanners[0])->banner_id;
        } else {
            $bannerId = optional($campaign->campaignBanners()->first())->banner_id;
        }

        // variants
        if (array_key_exists('variants', $data)) {
            $variants = $data['variants'];
        } elseif (!$campaign->campaignBanners->isEmpty()) {
            $variants = $campaign->campaignBanners;
        } else {
            $variants = $campaign->campaignBanners()
                                ->with('banner')
                                ->get();
        }

        return [
            $campaign,
            $bannerId,
            $variants,
            $selectedCountries,
            isset($data['countries_blacklist'])
                ? $data['countries_blacklist']
                : $blacklisted
        ];
    }

    public function getAllSegments(SegmentAggregator $segmentAggregator): Collection
    {
        try {
            $segments = $segmentAggregator->list();
        } catch (SegmentException $e) {
            $segments = new Collection();
            flash('Unable to fetch list of segments, please check the application configuration.')->error();
            Log::error($e->getMessage());
        }

        foreach ($segmentAggregator->getErrors() as $error) {
            flash(nl2br($error))->error();
            Log::error($error);
        }

        return $segments;
    }

    public function stats(
        Campaign $campaign,
        Request $request
    ) {
        $variants = $campaign->campaignBanners()->withTrashed()->with("banner")->get();
        $from = $request->input('from', 'today - 30 days');
        $to = $request->input('to', 'now');

        $variantBannerLinks = [];
        $variantBannerTexts = [];
        foreach ($variants as $variant) {
            if (!$variant->banner) {
                continue;
            }
            $variantBannerLinks[$variant->uuid] = route('banners.show', ['banner' => $variant->banner]);
            $variantBannerTexts[$variant->uuid] = $variant->banner->getTemplate()->text();
        }

        return view('campaigns.stats', [
            'beamJournalConfigured' => $this->beamJournalConfigured,
            'campaign' => $campaign,
            'variants' => $variants,
            'variantBannerLinks' => $variantBannerLinks,
            'variantBannerTexts' => $variantBannerTexts,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Campaign  $campaign
     * @return \Illuminate\Http\Response
     */
    public function destroy(Campaign $campaign)
    {
        //
    }
}
