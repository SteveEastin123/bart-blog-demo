"""Apply approved post-topic audit decisions for audit sequence 1751-2000."""

import apply_post_topic_link_audit_3151_3400 as audit


audit.START_SEQUENCE = 1751
audit.END_SEQUENCE = 2000
audit.EXPECTED_DECISIONS = 87


if __name__ == "__main__":
    audit.main()
