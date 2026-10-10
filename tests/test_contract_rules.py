"""Current Bitget contract bounds used by historical martingale orders."""

import unittest

from contract_rules import validate_market_order


CONTRACT = {
    "symbolStatus": "normal",
    "minLever": "1",
    "maxLever": "20",
    "minTradeNum": "0.1",
    "sizeMultiplier": "0.1",
    "volumePlace": "1",
    "minTradeUSDT": "5",
    "maxMarketOrderQty": "10",
}


class ContractOrderRuleTests(unittest.TestCase):
    def test_rounds_down_to_contract_step_before_balance_check(self):
        result = validate_market_order(10.99, 10, CONTRACT, available_balance=10.1, fee_rate=0.01)
        self.assertTrue(result["valid"])
        self.assertEqual(result["quantity"], 1.0)
        self.assertEqual(result["notional"], 10.0)
        self.assertEqual(result["margin_and_fee"], 10.1)

    def test_rejects_each_unplaceable_order_constraint(self):
        cases = (
            (5.0, 10.0, {"minTradeUSDT": "5.01"}, "notional", "minimum"),
            (5.0, 10.0, {"minTradeNum": "0.6"}, "quantity", "minimum"),
            (10.0, 10.0, {"maxMarketOrderQty": "0.9"}, "maximum", "maximum"),
            (5.0, 10.0, {"symbolStatus": "maintain"}, "status", "tradable"),
            (5.0, 10.0, {}, "balance", "insufficient"),
        )
        for notional, price, overrides, label, reason in cases:
            contract = {**CONTRACT, **overrides}
            with self.subTest(label=label):
                balance = 4.99 if label == "balance" else 100.0
                result = validate_market_order(
                    notional, price, contract, available_balance=balance, fee_rate=0.01,
                )
                self.assertFalse(result["valid"])
                self.assertIn(reason, result["reason"])

    def test_rejects_leverage_outside_public_contract_limits(self):
        result = validate_market_order(10, 10, CONTRACT, leverage=0.5)
        self.assertFalse(result["valid"])


if __name__ == "__main__":
    unittest.main()
