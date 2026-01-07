<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\LotType;
use App\Models\AmortizationTerm;
use App\Models\ApplicablePercentage;
use App\Models\Garden;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('api')->group(function () {
    // Get calculator data for dropdowns
    Route::get('/calculator/data', function () {
        // Get garden types dynamically from the database
        $gardenTypes = LotType::distinct('garden_type')
            ->whereNotNull('garden_type')
            ->pluck('garden_type')
            ->map(function ($gardenType) {
                return [
                    'value' => $gardenType,
                    'label' => $gardenType
                ];
            })
            ->values();

        // Get lot types
        $lotTypes = LotType::where('status', 'active')
            ->select('id', 'name', 'code', 'total_contract_price', 'garden_type')
            ->get()
            ->groupBy('garden_type');

        // Get down payment terms
        $dpTerms = AmortizationTerm::where('type', 'DOWNPAYMENT')
            ->where('status', 'active')
            ->select('id', 'name', 'code', 'numeric_value', 'percentage')
            ->orderBy('numeric_value')
            ->get();

        // Get amortization terms
        $amortTerms = AmortizationTerm::where('type', 'AMORTIZATION')
            ->where('status', 'active')
            ->select('id', 'name', 'code', 'numeric_value', 'percentage')
            ->orderBy('numeric_value')
            ->get();

        // Get applicable percentages
        $applicablePercentages = ApplicablePercentage::where('status', 'active')
            ->select('id', 'name', 'code', 'percentage')
            ->orderBy('percentage')
            ->get();

        return response()->json([
            'gardenTypes' => $gardenTypes,
            'lotTypes' => $lotTypes,
            'dpTerms' => $dpTerms,
            'amortTerms' => $amortTerms,
            'applicablePercentages' => $applicablePercentages
        ]);
    });

    // Calculate investment with correct formulas
    Route::post('/calculator/calculate', function (Request $request) {
        $lotTypeId = $request->input('lot_type_id');
        $modeOfPayment = $request->input('mode_of_payment');
        $dpTermId = $request->input('dp_term_id');
        $amortTermId = $request->input('amort_term_id');

        // Validate required inputs
        if (!$lotTypeId || !$modeOfPayment) {
            return response()->json(['error' => 'Missing required parameters'], 400);
        }

        // Validate mode-specific requirements
        if ($modeOfPayment === 'extended_dp' && !$dpTermId) {
            return response()->json(['error' => 'Down payment term is required for Extended DP mode'], 400);
        }

        if ($modeOfPayment === 'installment' && !$amortTermId) {
            return response()->json(['error' => 'Amortization term is required for Installment mode'], 400);
        }

        // Get lot type
        $lotType = LotType::find($lotTypeId);
        if (!$lotType) {
            return response()->json(['error' => 'Invalid lot type'], 400);
        }

        // Get amortization terms based on mode
        $dpTerm = null;
        $amortTerm = null;

        if ($modeOfPayment === 'extended_dp') {
            $dpTerm = AmortizationTerm::find($dpTermId);
            if (!$dpTerm) {
                return response()->json(['error' => 'Invalid down payment term'], 400);
            }
        }

        if ($modeOfPayment === 'installment') {
            $amortTerm = AmortizationTerm::find($amortTermId);
            if (!$amortTerm) {
                return response()->json(['error' => 'Invalid amortization term'], 400);
            }
        }

        // Get applicable percentages from database based on percentage values
        $spotCashPercentage = ApplicablePercentage::where('status', 'active')
            ->where('percentage', 0.15)
            ->first();
        $downpaymentPercentage = ApplicablePercentage::where('status', 'active')
            ->where('percentage', 0.25)
            ->first();
        $discountOnDPPercentage = ApplicablePercentage::where('status', 'active')
            ->where('percentage', 0.05)
            ->first();
        $reservationPercentage = ApplicablePercentage::where('status', 'active')
            ->where('percentage', 0.03)
            ->first();

        // Use default percentages if not found in database
        $spotCashRate = $spotCashPercentage ? $spotCashPercentage->percentage : 0.15;
        $downpaymentRate = $downpaymentPercentage ? $downpaymentPercentage->percentage : 0.25;
        $discountOnDPRate = $discountOnDPPercentage ? $discountOnDPPercentage->percentage : 0.05;
        $reservationRate = $reservationPercentage ? $reservationPercentage->percentage : 0.03;

        // Calculate using the correct formulas
        $glp = floatval($lotType->total_contract_price); // Gross List Price

        $eFund = $glp * 0.1; // EFUND = GLP * 0.1
        $evat = ($glp / 1.12) * 0.12; // EVAT = GLP/1.12 * 12%
        $nlpWithoutVAT = $glp - $evat - $eFund; // NLP_W/O_VAT = GLP - EVAT - EFUND

        $spotCash15Percent = $glp - ($nlpWithoutVAT * $spotCashRate); // 15% SPOT CASH
        $downpayment25Percent = $glp * $downpaymentRate; // 25% DOWNPAYMENT
        $discountOnDP5Percent = $downpayment25Percent - ($downpayment25Percent * $discountOnDPRate); // 5% DISC ON DP
        $reservation3Percent = $glp * $reservationRate; // 3% RESERVATION

        // Calculate Monthly DP only if Extended DP mode
        $monthlyDP = null;
        if ($modeOfPayment === 'extended_dp' && $dpTerm) {
            $monthlyDP = $downpayment25Percent / $dpTerm->numeric_value;
        }

        $nlpWithVAT = $glp - $downpayment25Percent; // NLP_W/_VAT = GLP - 25% DOWNPAYMENT

        // Calculate Monthly Amortization only if Installment mode
        $monthlyAmortization = null;
        if ($modeOfPayment === 'installment' && $amortTerm) {
            // Handle database decimal format - the percentage is stored as decimal like ".12" for 12%
            $interestRate = floatval($amortTerm->percentage);
            $months = $amortTerm->numeric_value;
            $monthlyRate = $interestRate / 12;

            // MA formula: (NLP_W/_VAT * (rate/12)) / (1 - (1 + (rate/12))^-months)
            if ($monthlyRate > 0) {
                $numerator = $nlpWithVAT * $monthlyRate;
                $denominator = 1 - pow(1 + $monthlyRate, -$months);
                $monthlyAmortization = $numerator / $denominator;
            } else {
                $monthlyAmortization = $nlpWithVAT / $months; // Simple division if no interest
            }
        }

        // Prepare response data
        $responseData = [
            'grossListPrice' => $glp,
            'spotCash15Percent' => $spotCash15Percent,
            'discountOnDP5Percent' => $discountOnDP5Percent,
            'downpayment25Percent' => $downpayment25Percent,
            'reservation3Percent' => $reservation3Percent,
            'monthlyDP' => $monthlyDP,
            'monthlyAmortization' => $monthlyAmortization,
            'lotType' => [
                'name' => $lotType->name,
                'code' => $lotType->code
            ]
        ];

        // Add mode-specific data
        if ($modeOfPayment === 'extended_dp' && $dpTerm) {
            $responseData['dpTermMonths'] = $dpTerm->numeric_value;
        }

        if ($modeOfPayment === 'installment' && $amortTerm) {
            $responseData['amortTermMonths'] = $amortTerm->numeric_value;
            $responseData['interestRate'] = floatval($amortTerm->percentage) * 100;
        }

        return response()->json($responseData);
    });

    // Contact form submission
    Route::post('/contact/submit', function (Request $request) {
        // Validate required fields
        $validated = $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'department' => 'nullable|string|max:255',
            'departmentEmail' => 'nullable|email',
            'message' => 'required|string|max:5000',
            'recipientEmail' => 'required|email',
        ]);

        // Ensure names are stored in uppercase
        $validated['firstName'] = strtoupper($validated['firstName']);
        $validated['lastName'] = strtoupper($validated['lastName']);
        $validated['email'] = strtolower(trim($validated['email']));

        try {
            // Generate unique customer number
            $latestCustomer = \App\Models\Customer::orderBy('id', 'desc')->first();
            $customerNumber = $latestCustomer ? 'CUST-' . str_pad($latestCustomer->id + 1, 4, '0', STR_PAD_LEFT) : 'CUST-0001';

            // Create customer record with defaults for required fields (retain existing logic)
            $customer = \App\Models\Customer::create([
                'customer_no' => $customerNumber,
                'contact_status' => 'PROSPECT',
                'application_date' => now(),
                'lastname' => $validated['lastName'],
                'firstname' => $validated['firstName'],
                'middlename' => '', // Default empty
                'sex' => 'Male', // Valid enum value
                'civil_status' => 'Single', // Valid enum value
                'citizenship' => 'Filipino', // Default value
                'birth_date' => '1900-01-01', // Default date
                'birth_place' => 'Unknown', // Default value
                'email' => $validated['email'],
                'home_no_street' => 'Unknown', // Default value
                'city' => 'Unknown', // Default value
                'barangay' => 'Unknown', // Default value
                'province' => 'Unknown', // Default value
                'sources_income' => 'Unknown', // Default value
                'est_monthly_income' => 0.00, // Default value
                'employer_name' => 'Unknown', // Default value
                'spouse_lastname' => '', // Default empty
                'spouse_firstname' => '', // Default empty
                'spouse_middlename' => '', // Default empty
                'spouse_contactno' => '', // Default empty
                'nextkin_fullname' => 'Unknown', // Default value
                'nextkin_relationship' => 'Unknown', // Default value
                'nextkin_contactno' => 'Unknown', // Default value
                'agent_id' => null, // Default null
                'is_agree' => 0, // Default false
                'coc_name' => '', // Default empty
                'created_by' => 1, // System user
                'updated_by' => 1, // System user
            ]);

            // Create phone record
            \DB::table('customer_phones')->insert([
                'customer_id' => $customer->id,
                'contact_no' => $validated['phone'],
            ]);

            // Send email notification
            try {
                $emailData = [
                    'firstName' => $validated['firstName'],
                    'lastName' => $validated['lastName'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'department' => $validated['department'] ?: 'General Inquiry',
                    'message' => $validated['message'],
                    'customerNumber' => $customerNumber,
                    'submissionTime' => now()->format('F j, Y, g:i A'),
                ];

                // Use department email from frontend or fallback to default
                $departmentEmail = $validated['departmentEmail'] ?: $validated['recipientEmail'];

                // Send to department-specific recipient
                \Mail::raw(
                    "New Contact Form Submission\n\n" .
                    "Customer Number: {$emailData['customerNumber']}\n" .
                    "Department: {$emailData['department']}\n\n" .
                    "Contact Information:\n" .
                    "Name: {$emailData['firstName']} {$emailData['lastName']}\n" .
                    "Email: {$emailData['email']}\n" .
                    "Phone: {$emailData['phone']}\n\n" .
                    "Message:\n{$emailData['message']}\n\n" .
                    "Submitted: {$emailData['submissionTime']}\n\n" .
                    "---\n" .
                    "This is an automated message from Loyola Gardens of Tanauan.\n" .
                    "Please do not reply to this email. For inquiries, please contact the relevant department directly.",
                    function ($message) use ($emailData, $validated, $departmentEmail) {
                        $message->to($departmentEmail)
                               ->replyTo($departmentEmail, $emailData['department'])
                               ->cc($validated['email']) // Send copy to sender
                               ->subject("[{$emailData['department']}] Inquiry from {$emailData['firstName']} {$emailData['lastName']}")
                               ->from('info.loyolatanauan@gmail.com', 'No Reply');
                    }
                );

            } catch (\Exception $mailError) {
                \Log::error('Email sending error: ' . $mailError->getMessage());
                // Continue even if email fails
            }

            return response()->json([
                'success' => true,
                'message' => 'Thank you for your inquiry. We will contact you shortly. A copy of your message has been sent to your email.',
                'customer_id' => $customer->id,
                'customer_no' => $customerNumber,
            ]);

        } catch (\Exception $e) {
            // Log error for debugging
            \Log::error('Contact form submission error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while submitting your request. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    });

    // Get products data for the Products page
    Route::get('/products', function () {
        try {
            // Get Garden Lot products from LotType table
            $gardenLots = LotType::where('status', 'active')
                ->where(function($query) {
                    $query->where('name', 'like', '%Garden%')
                          ->orWhere('name', 'like', '%garden%')
                          ->orWhere('garden_type', 'like', '%Garden%');
                })
                ->select('id', 'name', 'code', 'total_contract_price', 'garden_type')
                ->get();

            // Group garden lots by type to create price ranges
            $gardenLotProducts = [];
            if ($gardenLots->isNotEmpty()) {
                $minPrice = $gardenLots->min('total_contract_price');
                $maxPrice = $gardenLots->max('total_contract_price');

                $gardenLotProducts = [
                    'id' => 1,
                    'name' => 'Lawn Lot',
                    'description' => 'Providing a peaceful and dignified resting place for your loved ones. Our garden lots are meticulously maintained and offer a serene environment for remembrance.',
                    'price_range' => '₱' . number_format($minPrice, 0) . ' - ₱' . number_format($maxPrice, 0),
                    'min_price' => $minPrice,
                    'max_price' => $maxPrice,
                    'features' => [
                        'Well-maintained garden environment',
                        'Perpetual care included',
                        'Accessible location within memorial park',
                        'Various lot sizes available',
                        'Beautiful landscaping',
                        'Peaceful and serene atmosphere'
                    ],
                    'image' => '/images/lawn_lot1.jpg',
                    'slideshow_images' => [
                        '/images/lawn_lot1.jpg',
                        '/images/lawn_lot.jpg',
                        '/images/lawn_lot2.jpg',
                        '/images/garden_estate.jpg',
                        '/images/garden_estate1.jpg'
                    ],
                    'video' => '/video/garden_lot.mp4',
                    'lot_utilization' => [
                        'Two (2) full interments per lot',
                        'One (1) full interment and a maximum of four (4) sets of bone remains per lot',
                        'Maximum of eight (8) sets of bone remains per lot',
                        'Note: Only one (1) flat marker is allowed per lot regardless of number of interment'
                    ],
                    'categories' => [
                        [
                            'name' => 'SPECIAL LOT',
                            'description' => 'Inner lot located to the middle part of the whole lot section'
                        ],
                        [
                            'name' => 'PREMIUM LOT',
                            'description' => 'Located to 3rd and 4th row from the main road'
                        ],
                        [
                            'name' => 'PRIME LOT',
                            'description' => 'Located to besides pathways'
                        ],
                        [
                            'name' => 'EXTRA PRIME LOT',
                            'description' => 'Located besides the main road'
                        ]
                    ],
                    'availability' => 'Available',
                    'types' => $gardenLots->map(function($lot) {
                        return [
                            'id' => $lot->id,
                            'name' => $lot->name,
                            'code' => $lot->code,
                            'price' => $lot->total_contract_price,
                            'garden_type' => $lot->garden_type
                        ];
                    })->unique('name')->values()
                ];
            }

            // Get Family Estate data - this might come from Estates or specific LotType entries
            $familyEstates = LotType::where('status', 'active')
                ->where(function($query) {
                    $query->where('name', 'like', '%Estate%')
                          ->orWhere('name', 'like', '%Family%')
                          ->orWhere('name', 'like', '%estate%')
                          ->orWhere('garden_type', 'like', '%Estate%');
                })
                ->select('id', 'name', 'code', 'total_contract_price', 'garden_type')
                ->get();

            // Create Family Estate product
            $familyEstateProducts = [];
            if ($familyEstates->isNotEmpty()) {
                $minPrice = $familyEstates->min('total_contract_price');
                $maxPrice = $familyEstates->max('total_contract_price');

                $familyEstateProducts = [
                    'id' => 2,
                    'name' => 'Family Estate',
                    'description' => 'Exclusive family estates offering privacy, prestige, and ample space for generations to come. These properties provide an exclusive sanctuary for your family legacy.',
                    'price_range' => '₱' . number_format($minPrice, 0) . ' - ₱' . number_format($maxPrice, 0),
                    'min_price' => $minPrice,
                    'max_price' => $maxPrice,
                    'features' => [
                        'Spacious family plots',
                        'Exclusive privacy features',
                        'Location within memorial park',
                        'Customizable memorial options',
                        'Enhanced security',
                        'Priority maintenance services'
                    ],
                    'image' => '/images/garden_lot2.jpg',
                    'slideshow_images' => [
                        '/images/garden_lot2.jpg',
                        '/images/family_estate.jpg',
                        '/images/family_estate1.jpg',
                        '/images/family_estate2.jpg',
                        '/images/family_estate3.jpg'
                    ],
                    'video' => '/video/garden_lot.mp4',
                    'lot_specifications' => [
                        '12 Lots Configuration: Lot Area 30 sqm, Buildable Area 20 sqm',
                        '16 Lots Configuration: Lot Area 40 sqm, Buildable Area 28 sqm',
                        'Building Height: 6 meters high maximum'
                    ],
                    'categories' => [
                        [
                            'name' => 'SPECIAL LOT',
                            'description' => 'Inner lot located to the middle part of the whole lot section'
                        ],
                        [
                            'name' => 'PREMIUM LOT',
                            'description' => 'Located to 3rd and 4th row from the main road'
                        ],
                        [
                            'name' => 'PRIME LOT',
                            'description' => 'Located to besides pathways'
                        ],
                        [
                            'name' => 'EXTRA PRIME LOT',
                            'description' => 'Located besides the main road'
                        ]
                    ],
                    'availability' => 'Limited',
                    'types' => $familyEstates->map(function($estate) {
                        return [
                            'id' => $estate->id,
                            'name' => $estate->name,
                            'code' => $estate->code,
                            'price' => $estate->total_contract_price,
                            'garden_type' => $estate->garden_type
                        ];
                    })->unique('name')->values()
                ];
            }

            // If no data found in database, return default products
            $products = [];

            if ($gardenLotProducts) {
                $products[] = $gardenLotProducts;
            } else {
                // Default Garden Lot product
                $products[] = [
                    'id' => 1,
                    'name' => 'Lawn Lot',
                    'description' => 'Providing a peaceful and dignified resting place for your loved ones.',
                    'price_range' => '₱95,000 - ₱110,000',
                    'min_price' => 95000,
                    'max_price' => 110000,
                    'features' => [
                        'Well-maintained garden environment',
                        'Perpetual care included',
                        'Accessible location',
                        'Various lot sizes available'
                    ],
                    'image' => '/images/lawn_lot1.jpg',
                    'slideshow_images' => [
                        '/images/lawn_lot1.jpg',
                        '/images/lawn_lot.jpg',
                        '/images/lawn_lot2.jpg',
                        '/images/garden_estate.jpg',
                        '/images/garden_estate1.jpg'
                    ],
                    'video' => '/video/garden_lot.mp4',
                    'lot_utilization' => [
                        'Two (2) full interments per lot',
                        'One (1) full interment and a maximum of four (4) sets of bone remains per lot',
                        'Maximum of eight (8) sets of bone remains per lot',
                        'Note: Only one (1) flat marker is allowed per lot regardless of number of interment'
                    ],
                    'categories' => [
                        [
                            'name' => 'SPECIAL LOT',
                            'description' => 'Inner lot located to the middle part of the whole lot section'
                        ],
                        [
                            'name' => 'PREMIUM LOT',
                            'description' => 'Located to 3rd and 4th row from the main road'
                        ],
                        [
                            'name' => 'PRIME LOT',
                            'description' => 'Located to besides pathways'
                        ],
                        [
                            'name' => 'EXTRA PRIME LOT',
                            'description' => 'Located besides the main road'
                        ]
                    ],
                    'availability' => 'Available',
                    'types' => []
                ];
            }

            if ($familyEstateProducts) {
                $products[] = $familyEstateProducts;
            } else {
                // Default Family Estate product
                $products[] = [
                    'id' => 2,
                    'name' => 'Family Estate',
                    'description' => 'Exclusive family estates offering privacy, prestige, and ample space for generations to come.',
                    'price_range' => '₱1,560,000 - ₱2,080,000',
                    'min_price' => 1560000,
                    'max_price' => 2080000,
                    'features' => [
                        'Spacious family plots',
                        'Exclusive privacy features',
                        'Location within memorial park',
                        'Customizable memorial options'
                    ],
                    'image' => '/images/garden_lot2.jpg',
                    'slideshow_images' => [
                        '/images/garden_lot2.jpg',
                        '/images/family_estate.jpg',
                        '/images/family_estate1.jpg',
                        '/images/family_estate2.jpg',
                        '/images/family_estate3.jpg'
                    ],
                    'video' => '/video/garden_lot.mp4',
                    'lot_specifications' => [
                        '12 Lots Configuration: Lot Area 30 sqm, Buildable Area 20 sqm',
                        '16 Lots Configuration: Lot Area 40 sqm, Buildable Area 28 sqm',
                        'Building Height: 6 meters high maximum'
                    ],
                    'categories' => [
                        [
                            'name' => 'SPECIAL LOT',
                            'description' => 'Inner lot located to the middle part of the whole lot section'
                        ],
                        [
                            'name' => 'PREMIUM LOT',
                            'description' => 'Located to 3rd and 4th row from the main road'
                        ],
                        [
                            'name' => 'PRIME LOT',
                            'description' => 'Located to besides pathways'
                        ],
                        [
                            'name' => 'EXTRA PRIME LOT',
                            'description' => 'Located besides the main road'
                        ]
                    ],
                    'availability' => 'Limited',
                    'types' => []
                ];
            }

            return response()->json($products);

        } catch (\Exception $e) {
            \Log::error('Products API error: ' . $e->getMessage());

            // Return default products on error
            return response()->json([
                [
                    'id' => 1,
                    'name' => 'Lawn Lot',
                    'description' => 'Providing a peaceful and dignified resting place for your loved ones.',
                    'price_range' => '₱95,000 - ₱110,000',
                    'min_price' => 95000,
                    'max_price' => 110000,
                    'features' => [
                        'Well-maintained garden environment',
                        'Perpetual care included',
                        'Accessible location',
                        'Various lot sizes available'
                    ],
                    'image' => '/images/lawn_lot1.jpg',
                    'slideshow_images' => [
                        '/images/lawn_lot1.jpg',
                        '/images/lawn_lot.jpg',
                        '/images/lawn_lot2.jpg',
                        '/images/garden_estate.jpg',
                        '/images/garden_estate1.jpg'
                    ],
                    'video' => '/video/garden_lot.mp4',
                    'lot_utilization' => [
                        'Two (2) full interments per lot',
                        'One (1) full interment and a maximum of four (4) sets of bone remains per lot',
                        'Maximum of eight (8) sets of bone remains per lot',
                        'Note: Only one (1) flat marker is allowed per lot regardless of number of interment'
                    ],
                    'categories' => [
                        [
                            'name' => 'SPECIAL LOT',
                            'description' => 'Inner lot located to the middle part of the whole lot section'
                        ],
                        [
                            'name' => 'PREMIUM LOT',
                            'description' => 'Located to 3rd and 4th row from the main road'
                        ],
                        [
                            'name' => 'PRIME LOT',
                            'description' => 'Located to besides pathways'
                        ],
                        [
                            'name' => 'EXTRA PRIME LOT',
                            'description' => 'Located besides the main road'
                        ]
                    ],
                    'availability' => 'Available',
                    'types' => []
                ],
                [
                    'id' => 2,
                    'name' => 'Family Estate',
                    'description' => 'Exclusive family estates offering privacy, prestige, and ample space for generations to come.',
                    'price_range' => '₱1,560,000 - ₱2,080,000',
                    'min_price' => 1560000,
                    'max_price' => 2080000,
                    'features' => [
                        'Spacious family plots',
                        'Exclusive privacy features',
                        'Location within memorial park',
                        'Customizable memorial options'
                    ],
                    'image' => '/images/garden_lot2.jpg',
                    'slideshow_images' => [
                        '/images/garden_lot2.jpg',
                        '/images/family_estate.jpg',
                        '/images/family_estate1.jpg',
                        '/images/family_estate2.jpg',
                        '/images/family_estate3.jpg'
                    ],
                    'video' => '/video/garden_lot.mp4',
                    'lot_specifications' => [
                        '12 Lots Configuration: Lot Area 30 sqm, Buildable Area 20 sqm',
                        '16 Lots Configuration: Lot Area 40 sqm, Buildable Area 28 sqm',
                        'Building Height: 6 meters high maximum'
                    ],
                    'categories' => [
                        [
                            'name' => 'SPECIAL LOT',
                            'description' => 'Inner lot located to the middle part of the whole lot section'
                        ],
                        [
                            'name' => 'PREMIUM LOT',
                            'description' => 'Located to 3rd and 4th row from the main road'
                        ],
                        [
                            'name' => 'PRIME LOT',
                            'description' => 'Located to besides pathways'
                        ],
                        [
                            'name' => 'EXTRA PRIME LOT',
                            'description' => 'Located besides the main road'
                        ]
                    ],
                    'availability' => 'Limited',
                    'types' => []
                ]
            ]);
        }
    });
});