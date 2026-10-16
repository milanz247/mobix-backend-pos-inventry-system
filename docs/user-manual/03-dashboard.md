# 3. Dashboard

Open **Dashboard** from the sidebar. Access requires `dashboard.view`. Data is scoped to the user's authorized locations and uses the business timezone.

## Today’s activity

The overview shows:

- **Sales**: total value of sales posted today (gross sale totals, before returns are subtracted).
- **Completed sales**: count of sale transactions posted today.
- **Cash received**: cash payment amounts recorded today; this is not all payment methods.
- **Returns** and **Refunds**: return value and refund amount processed today, shown separately.
- **Outstanding**: current balances due across accessible sales, adjusted for returns and refunds. It is a balance measure, not today's sales.

## Performance trend

The trend chart covers the preceding 30 calendar days, including today. It presents daily **Net sales** (sales less returns) and **Gross profit** calculated from sale-line cost snapshots and the corresponding returned-line costs. These figures are not a full accounting profit: operating expenses and other costs are not included. The dashboard includes **Open sales reports** for users with report access.

The dashboard shows no general-purpose location/date filter in its current UI. The set of included locations follows the user's access assignment.
